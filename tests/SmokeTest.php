<?php

namespace Slipstream\Tests;

use Illuminate\Support\Facades\Schema;
use Slipstream\Settings\SettingsRepository;

class SmokeTest extends TestCase
{
    public function test_package_migrations_create_all_tables(): void
    {
        foreach ([
            'slipstream_servers',
            'slipstream_pipelines',
            'slipstream_deployments',
            'slipstream_deployment_events',
            'slipstream_deployment_hooks',
            'slipstream_webhook_deliveries',
            'slipstream_settings',
            'slipstream_env_audits',
            'slipstream_activity_log',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table}");
        }

        $this->assertTrue(Schema::hasColumn('slipstream_deployments', 'warnings'));
        $this->assertTrue(Schema::hasColumn('slipstream_webhook_deliveries', 'server_name'));
    }

    public function test_settings_repository_encrypts_marked_keys(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('repo_url', 'git@github.com:org/app.git');

        $raw = \Slipstream\Models\Setting::query()->where('key', 'repo_url')->value('value');
        $this->assertNotSame('git@github.com:org/app.git', $raw, 'repo_url stored in plaintext');
        $this->assertSame('git@github.com:org/app.git', $settings->get('repo_url'));
    }
}
