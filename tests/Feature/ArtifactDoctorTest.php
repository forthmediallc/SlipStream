<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Slipstream\Models\DeploymentHook;
use Slipstream\Tests\Support\ArtifactFixture;
use Slipstream\Tests\TestCase;

/**
 * slipstream:doctor in artifact mode. The bare layout fails other checks,
 * so every run exits 1; what matters is what each check says.
 */
class ArtifactDoctorTest extends TestCase
{
    protected ArtifactFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response('invalid signature', 401)]);
        $this->fixture = (new ArtifactFixture)->configure();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();

        parent::tearDown();
    }

    public function test_artifact_mode_on_the_webhook_trigger_is_a_failure(): void
    {
        config()->set('slipstream.trigger.mode', 'webhook');

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('artifact releases need SLIPSTREAM_TRIGGER=poll')
            ->assertExitCode(1);
    }

    public function test_a_tag_pattern_ref_is_a_failure(): void
    {
        config()->set('slipstream.trigger.ref', 'v*');

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('the deploy ref is a tag pattern')
            ->assertExitCode(1);
    }

    public function test_missing_keys_disk_or_environment_fail_naming_the_variable(): void
    {
        config()->set('slipstream.artifacts.public_keys', '');
        $this->artisan('slipstream:doctor')->expectsOutputToContain('SLIPSTREAM_RELEASE_PUBLIC_KEYS is empty')->assertExitCode(1);

        config()->set('slipstream.artifacts.public_keys', 'test-key:'.$this->fixture->publicKey);
        config()->set('slipstream.artifacts.disk', 'nope');
        $this->artisan('slipstream:doctor')->expectsOutputToContain('SLIPSTREAM_ARTIFACTS_DISK names "nope"')->assertExitCode(1);

        config()->set('slipstream.artifacts.disk', 'artifacts');
        config()->set('slipstream.release.environment', null);
        $this->artisan('slipstream:doctor')->expectsOutputToContain('SLIPSTREAM_RELEASE_ENVIRONMENT is not set')->assertExitCode(1);
    }

    public function test_a_verified_pointer_passes_and_the_git_checks_are_skipped(): void
    {
        $this->fixture->writeCheckout()->package()->publish();

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('artifact · disk "artifacts" · prefix app · environment production · keys test-key')
            ->expectsOutputToContain('refs/heads/production → aaaaaaa · sequence')
            ->expectsOutputToContain('artifact mode: nodes never run git')
            ->assertExitCode(1);
    }

    public function test_a_pointer_that_does_not_verify_is_a_failure(): void
    {
        $this->fixture->writeCheckout()->package()->publish();
        $this->fixture->resignPointer($this->fixture->otherSigner());

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('refused: signature does not verify')
            ->assertExitCode(1);
    }

    public function test_the_live_release_is_checked_against_its_manifest(): void
    {
        $this->fixture->writeCheckout()->package()->publish();
        $live = $this->fixture->unpackInto($this->fixture->appRoot.'/current');

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('matches its signed manifest')
            ->assertExitCode(1);

        file_put_contents($live.'/app/Hello.php', '<?php // tampered');

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('files differ from the manifest: 1 modified (app/Hello.php)')
            ->assertExitCode(1);
    }

    public function test_stored_hooks_are_an_unsigned_path_and_are_warned_about(): void
    {
        DeploymentHook::query()->create(['step' => 'swap', 'position' => 'before', 'name' => 'Warm', 'script' => 'echo warm', 'enabled' => true, 'sort_order' => 10]);

        $this->artisan('slipstream:doctor')
            ->expectsOutputToContain('1 stored hook(s) run unsigned')
            ->assertExitCode(1);
    }
}
