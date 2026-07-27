<?php

namespace Slipstream\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Slipstream\Environment\EnvSync;
use Slipstream\Models\EnvPush;
use Slipstream\Tests\TestCase;

class EnvApplyTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/ss-envapply-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        config()->set('slipstream.app_root', $this->root);
        file_put_contents($this->root.'/.env', "APP_ENV=production\nSLIPSTREAM_SERVER=test-node\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/.env*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function pending(array $attrs = []): EnvPush
    {
        return EnvPush::query()->create(array_merge([
            'batch' => 'b-'.bin2hex(random_bytes(3)),
            'server_name' => 'test-node',
            'contents' => "APP_ENV=production\nNEW=1\n",
            'base_hash' => null,
            'preserve_keys' => true,
            'status' => EnvPush::STATUS_PENDING,
            'user_id' => 5,
        ], $attrs));
    }

    public function test_apply_pending_writes_backs_up_audits_and_snapshots(): void
    {
        $this->pending();

        $push = app(EnvSync::class)->applyPending();

        $this->assertNotNull($push);
        $this->assertSame(EnvPush::STATUS_APPLIED, $push->fresh()->status);
        $this->assertNotNull($push->fresh()->applied_at);

        $env = file_get_contents($this->root.'/.env');
        $this->assertStringContainsString('NEW=1', $env);
        $this->assertStringContainsString('SLIPSTREAM_SERVER=test-node', $env, 'preserve merge kept the per-server key');

        $this->assertDatabaseHas('slipstream_env_audits', ['server_name' => 'test-node', 'user_id' => 5]);
        $this->assertDatabaseHas('slipstream_env_snapshots', ['server_name' => 'test-node']);
    }

    public function test_base_hash_mismatch_fails_without_touching_the_file(): void
    {
        $original = file_get_contents($this->root.'/.env');
        $this->pending(['base_hash' => 'deadbeef', 'preserve_keys' => false, 'contents' => "REPLACED=1\n"]);

        $push = app(EnvSync::class)->applyPending();

        $this->assertSame(EnvPush::STATUS_FAILED, $push->fresh()->status);
        $this->assertStringContainsString('changed', $push->fresh()->error);
        $this->assertSame($original, file_get_contents($this->root.'/.env'), 'file must be untouched on mismatch');
    }

    public function test_already_applied_fast_path_does_not_rewrite(): void
    {
        // Final content equals the current file → mark applied, no write/backup.
        $this->pending(['preserve_keys' => false, 'contents' => file_get_contents($this->root.'/.env')]);

        $push = app(EnvSync::class)->applyPending();

        $this->assertSame(EnvPush::STATUS_APPLIED, $push->fresh()->status);
        $this->assertEmpty(glob($this->root.'/.env.slipstream.bak.*') ?: [], 'fast-path must not create a backup');
    }

    public function test_no_pending_returns_null(): void
    {
        $this->assertNull(app(EnvSync::class)->applyPending());
    }

    public function test_recent_applying_row_is_not_claimed(): void
    {
        $this->pending(['status' => EnvPush::STATUS_APPLYING]);

        $this->assertNull(app(EnvSync::class)->applyPending());
    }

    public function test_stale_applying_row_is_recovered(): void
    {
        $push = $this->pending(['status' => EnvPush::STATUS_APPLYING]);
        EnvPush::query()->whereKey($push->id)->update(['updated_at' => now()->subMinutes(10)]);

        $applied = app(EnvSync::class)->applyPending();

        $this->assertNotNull($applied);
        $this->assertSame(EnvPush::STATUS_APPLIED, $applied->fresh()->status);
    }

    public function test_env_apply_command_applies_pending(): void
    {
        $this->pending();

        $this->artisan('slipstream:env-apply')->assertExitCode(0);

        $this->assertStringContainsString('NEW=1', file_get_contents($this->root.'/.env'));
        $this->assertSame(EnvPush::STATUS_APPLIED, EnvPush::query()->first()->status);
    }

    public function test_heartbeat_command_applies_pending(): void
    {
        $this->pending();

        $this->artisan('slipstream:heartbeat')->assertExitCode(0);

        $this->assertStringContainsString('NEW=1', file_get_contents($this->root.'/.env'));
        $this->assertSame(EnvPush::STATUS_APPLIED, EnvPush::query()->first()->status);
    }

    public function test_corrupt_ciphertext_fails_with_app_key_message(): void
    {
        $push = $this->pending();
        // Bypass the cast to plant undecryptable bytes (simulates APP_KEY rotation).
        DB::table('slipstream_env_pushes')->where('id', $push->id)->update(['contents' => 'not-real-ciphertext']);

        $result = app(EnvSync::class)->applyPending();

        $this->assertSame(EnvPush::STATUS_FAILED, $result->fresh()->status);
        $this->assertStringContainsString('decrypt', $result->fresh()->error);
    }
}
