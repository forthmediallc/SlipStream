<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Slipstream\Console\Commands\VerifyCommand;
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

    public function test_a_release_deployed_from_git_has_nothing_to_verify(): void
    {
        $plain = $this->fixture->appRoot.'/plain';
        mkdir($plain);
        file_put_contents($plain.'/RELEASE_SHA', str_repeat('c', 40));

        $this->artisan('slipstream:verify', ['--release' => $plain])
            ->expectsOutputToContain('Nothing to verify')
            ->assertExitCode(VerifyCommand::NOTHING_TO_VERIFY);
    }
}
