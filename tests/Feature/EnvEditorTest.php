<?php

namespace Slipstream\Tests\Feature;

use Slipstream\Environment\EnvEditor;
use Slipstream\Models\EnvAudit;
use Slipstream\Tests\TestCase;

class EnvEditorTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ss-env-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        config()->set('slipstream.app_root', $this->root);
        file_put_contents($this->root.'/.env', "APP_ENV=production\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/.env*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_write_targets_shared_env_backs_up_and_audits(): void
    {
        $editor = app(EnvEditor::class);
        $this->assertSame($this->root.DIRECTORY_SEPARATOR.'.env', $editor->path());

        $result = $editor->write("APP_ENV=production\nNEW_KEY=1\n", userId: 42, note: 'added key');

        $this->assertStringContainsString('NEW_KEY=1', file_get_contents($this->root.'/.env'));
        $this->assertNotEmpty($result['backup_path']);
        $this->assertFileExists($result['backup_path']);
        $this->assertStringContainsString('APP_ENV=production', file_get_contents($result['backup_path']));

        $this->assertDatabaseHas('slipstream_env_audits', [
            'user_id' => 42,
            'action' => 'save',
            'server_name' => 'test-node',
        ]);
        $this->assertSame(1, EnvAudit::query()->count());
    }
}
