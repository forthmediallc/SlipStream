<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Slipstream\Models\DeploymentHook;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Tests\TestCase;
use Slipstream\Webhooks\WebhookSourceRanges;

class DoctorCommandTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'slipstream-doctor-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        config()->set('slipstream.app_root', $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    /**
     * A layout with nothing set up: the checks should name the gaps rather than
     * blow up.
     */
    public function test_an_unprovisioned_layout_reports_failures_rather_than_crashing(): void
    {
        Http::fake(['*' => Http::response('invalid signature', 401)]);

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('current symlink')
            ->expectsOutputToContain('shared .env')
            ->assertExitCode(1);
    }

    public function test_a_419_from_the_hook_is_reported_as_a_csrf_problem(): void
    {
        Http::fake(['*' => Http::response('Page Expired', 419)]);

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('CSRF')
            ->expectsOutputToContain('validateCsrfTokens')
            ->assertExitCode(1);
    }

    /**
     * The vault's own credentials on a node that still has the dashboard,
     * the .env editor and env sync: the one configuration the sentinel list
     * exists to catch, and a blocker rather than a note.
     */
    public function test_a_sentinel_key_present_with_pci_mode_off_is_a_failure(): void
    {
        Http::fake(['*' => Http::response('invalid signature', 401)]);
        config()->set('slipstream.pci.sentinel_keys', ['VAULT_DB_HOST', 'GATEWAY_SECRET_ARN']);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'.env', "APP_KEY=x\nexport VAULT_DB_HOST=vault.internal\n");

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('VAULT_DB_HOST present but PCI mode is off')
            ->expectsOutputToContain('slipstream:install --pci')
            ->assertExitCode(1);
    }

    public function test_absent_sentinel_keys_pass_and_pci_mode_is_reported_off(): void
    {
        Http::fake(['*' => Http::response('invalid signature', 401)]);
        config()->set('slipstream.pci.sentinel_keys', ['VAULT_DB_HOST']);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'.env', "APP_KEY=x\nVAULT_DB_HOST_BACKUP=not-the-key\n");

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('none of VAULT_DB_HOST present')
            ->expectsOutputToContain('any address (signature verified)')
            ->assertExitCode(1); // the bare layout still fails elsewhere
    }

    public function test_an_enforced_signature_counts_as_a_healthy_hook(): void
    {
        Http::fake(['*' => Http::response('invalid signature', 401)]);

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('signature enforced')
            ->assertExitCode(1); // other checks still fail on this bare layout
    }

    public function test_it_passes_on_a_fully_set_up_layout(): void
    {
        Http::fake(['*' => Http::response('invalid signature', 401)]);

        $release = $this->root.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.'20260101_000000_abc1234';
        mkdir($release, 0755, true);
        chmod($this->root.DIRECTORY_SEPARATOR.'releases', 0755);

        foreach (['framework/views', 'framework/cache/data', 'framework/sessions', 'logs'] as $sub) {
            mkdir($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $sub), 0777, true);
        }

        file_put_contents($this->root.DIRECTORY_SEPARATOR.'.env', "APP_KEY=x\n");

        if (! @symlink($release, $this->root.DIRECTORY_SEPARATOR.'current')) {
            $this->markTestSkipped('creating symlinks is not permitted in this environment.');
        }

        // The git mirror is the only remaining gap, and it is reported as such.
        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('shared .env')
            ->expectsOutputToContain('git mirror')
            ->assertExitCode(1);
    }

    /**
     * The production failure this check exists for: repo.git reachable, top
     * level chowned correctly, but one root-owned objects/xx/ directory left
     * by an earlier root-run fetch. ls-remote passes; the next deploy dies at
     * git fetch with "insufficient permission for adding an object".
     */
    public function test_a_mirror_the_deploy_user_cannot_write_into_fails_with_the_fetch_symptom(): void
    {
        $this->requirePosixNonRoot();
        Http::fake(['*' => Http::response('invalid signature', 401)]);

        $repo = $this->root.DIRECTORY_SEPARATOR.'repo.git';
        mkdir($repo.'/objects/ab', 0755, true);
        mkdir($repo.'/objects/cd', 0755, true);
        // Everything is mine, so as ME it is all writable — the salted
        // directory is the one that is not.
        chmod($repo.'/objects/cd', 0555);

        $me = \Slipstream\Diagnostics\FpmPool::currentUser();

        // Assert on the fix line, not the two-column summary: the renderer pads
        // that column with dot leaders, which breaks a plain substring match.
        $this->artisan("slipstream:doctor --fpm-user={$me}")
            ->expectsOutputToContain('deploy user can write')
            ->expectsOutputToContain('insufficient permission for adding an object')
            ->expectsOutputToContain("sudo chown -R {$me}:{$me} {$repo}")
            ->assertExitCode(1);
    }

    public function test_a_layout_the_deploy_user_owns_passes_the_write_check(): void
    {
        $this->requirePosixNonRoot();
        Http::fake(['*' => Http::response('invalid signature', 401)]);

        mkdir($this->root.'/repo.git/objects/ab', 0755, true);
        mkdir($this->root.'/releases', 0755, true);
        mkdir($this->root.'/deploy/logs', 0750, true);

        $me = \Slipstream\Diagnostics\FpmPool::currentUser();

        $this->artisan("slipstream:doctor --fpm-user={$me}")
            ->expectsOutputToContain("{$me} can write repo.git, releases/, deploy/")
            ->assertExitCode(1); // other checks still fail on this partial layout
    }

    /**
     * Reachability was tested with the asking user's key. When that is not
     * the deploy user, saying "reachable" would bless root's key for a fetch
     * that runs as somebody else.
     */
    public function test_git_reachability_as_another_user_is_only_a_warning(): void
    {
        $this->requirePosixNonRoot();
        Http::fake(['*' => Http::response('invalid signature', 401)]);

        // A bare mirror with one commit, so `ls-remote origin HEAD` (against
        // itself) has a HEAD to answer with — an empty repo would fail the
        // reachability probe for an unrelated reason.
        $repo = $this->root.DIRECTORY_SEPARATOR.'repo.git';
        $work = $this->root.DIRECTORY_SEPARATOR.'work';
        $git = fn (array $args, ?string $cwd = null) => (new \Symfony\Component\Process\Process(['git', ...$args], $cwd))->setTimeout(30)->mustRun();
        try {
            mkdir($work, 0777, true);
            file_put_contents($work.'/README', 'x');
            $git(['init', '-q'], $work);
            $git(['-c', 'user.email=t@e.com', '-c', 'user.name=T', 'add', '-A'], $work);
            $git(['-c', 'user.email=t@e.com', '-c', 'user.name=T', 'commit', '-qm', 'init'], $work);
            $git(['clone', '-q', '--mirror', $work, $repo]);
            $git(['--git-dir='.$repo, 'remote', 'set-url', 'origin', $repo]);
        } catch (\Throwable) {
            $this->markTestSkipped('git is not available.');
        }

        $other = $this->otherPosixUser();

        // Assert on the fix line: the summary column carries dot leaders.
        $this->artisan("slipstream:doctor --fpm-user={$other}")
            ->expectsOutputToContain("sudo -u {$other} php artisan slipstream:doctor")
            ->expectsOutputToContain('/root/.ssh')
            ->assertExitCode(1);
    }

    /**
     * Doctor runs from the CLI, where PHP_BINARY is a CLI; what matters is
     * the PHP an FPM worker (dashboard, webhook) would spawn deploys with. An
     * explicit SLIPSTREAM_PHP is reported as such …
     */
    public function test_it_reports_the_php_that_dashboard_and_webhook_deploys_use(): void
    {
        Http::fake(['*' => Http::response('invalid signature', 401)]);
        config()->set('slipstream.deploy.php_binary', PHP_BINARY);

        // One substring: the label and the message share a line, and the
        // console test harness satisfies at most one expectation per write.
        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain(PHP_BINARY.' (SLIPSTREAM_PHP)')
            ->assertExitCode(1); // the bare layout still fails elsewhere
    }

    /**
     * … and one that does not exist is a blocker named with its symptom,
     * rather than something to work out from a deploy stuck in "pending".
     */
    public function test_an_unusable_php_binary_is_a_failure_with_the_pending_symptom(): void
    {
        Http::fake(['*' => Http::response('invalid signature', 401)]);
        config()->set('slipstream.deploy.php_binary', $this->root.DIRECTORY_SEPARATOR.'no-such-php');

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('not an executable file')
            ->expectsOutputToContain('sit in "pending"')
            ->assertExitCode(1);
    }

    protected function requirePosixNonRoot(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || ! function_exists('posix_geteuid')) {
            $this->markTestSkipped('needs POSIX ownership.');
        }
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root can write anything; nothing to prove.');
        }
    }

    public function test_a_stored_hook_of_several_lines_without_set_e_is_a_warning(): void
    {
        Http::fake(['*' => Http::response('invalid signature', 401)]);
        DeploymentHook::query()->create([
            'step' => 'composer', 'position' => 'after', 'name' => 'Build docs',
            'script' => "npm run docs\nrm -rf node_modules", 'enabled' => true, 'sort_order' => 10,
        ]);

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('without set -e: "Build docs" (#')
            ->assertExitCode(1); // the bare layout fails elsewhere
    }

    public function test_a_keyword_never_downloaded_fails_the_webhook_source_check(): void
    {
        Http::fake(['*' => Http::response('invalid signature', 401)]);
        config()->set('slipstream.webhook.allowed_ips', ['github']);

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('github ranges never downloaded')
            ->assertExitCode(1);
    }

    public function test_stale_ranges_are_a_warning(): void
    {
        Http::fake(['*' => Http::response('invalid signature', 401)]);
        config()->set('slipstream.webhook.allowed_ips', ['github']);
        app(SettingsRepository::class)->set(WebhookSourceRanges::SETTING, ['github' => [
            'cidrs' => ['140.82.112.0/20'], 'etag' => null, 'fetched_at' => now()->subDays(3)->toIso8601String(),
            'last_attempt_at' => null, 'last_error' => null,
        ]]);

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('restricted to github (1 ranges · downloaded 3 days ago)')
            ->expectsOutputToContain('older than 48 hours')
            ->assertExitCode(1);
    }

    public function test_an_entry_that_is_neither_address_nor_keyword_fails(): void
    {
        Http::fake(['*' => Http::response('invalid signature', 401)]);
        config()->set('slipstream.webhook.allowed_ips', ['gitlab']);

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('neither an address nor a provider keyword')
            ->assertExitCode(1);
    }

    protected function otherPosixUser(): string
    {
        $me = \Slipstream\Diagnostics\FpmPool::currentUser();
        foreach (['nobody', 'daemon', 'www-data', 'root'] as $candidate) {
            if ($candidate !== $me && is_array(@posix_getpwnam($candidate))) {
                return $candidate;
            }
        }
        $this->markTestSkipped('no second user account available.');
    }
}
