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
        @mkdir($this->release.'/storage/logs', 0777, true);
        file_put_contents($this->release.'/storage/logs/laravel.log', 'noise');
        file_put_contents($this->release.'/.env', 'APP_KEY=x');
        config()->set('slipstream.deploy.shared_paths', ['public/uploads' => 'shared/uploads']);
        @mkdir($this->release.'/public/uploads', 0777, true);
        file_put_contents($this->release.'/public/uploads/photo.jpg', 'jpg');

        $this->artisan('slipstream:verify')->assertExitCode(0);
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
