<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Slipstream\Database\SlipstreamMigration;

return new class extends SlipstreamMigration
{
    public function up(): void
    {
        $this->createIfMissing('slipstream_servers', function (Blueprint $table) {
            $table->id();
            $table->string('server_name')->unique();
            $table->string('hostname')->nullable();
            $table->string('current_sha', 64)->nullable();
            $table->string('php_version', 32)->nullable();
            $table->unsignedBigInteger('disk_free_bytes')->nullable();
            $table->string('fpm_service')->nullable();
            $table->string('horizon_program')->nullable();
            $table->string('reverb_program')->nullable();
            $table->boolean('run_migrations')->default(true);
            $table->boolean('enabled')->default(true);
            $table->boolean('paused')->default(false);
            $table->boolean('horizon_running')->nullable();
            $table->boolean('reverb_running')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();
        });

        $this->createIfMissing('slipstream_pipelines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('branch_pattern')->default('main');
            $table->boolean('auto_deploy')->default(true);
            $table->boolean('enabled')->default(true);
            $table->json('server_names')->nullable(); // null = all servers
            $table->timestamps();
        });

        $this->createIfMissing('slipstream_deployments', function (Blueprint $table) {
            $table->id();
            $table->string('server_name')->index();
            $table->string('sha', 64)->nullable()->index();
            $table->string('branch')->nullable();
            $table->string('commit_message')->nullable();
            $table->string('commit_author')->nullable();
            $table->string('trigger', 32)->default('manual'); // webhook|manual|rollback|catch-up
            $table->string('status', 32)->default('pending')->index(); // pending|building|deployed|failed|rolled-back
            $table->unsignedInteger('duration_ms')->nullable();
            $table->foreignId('initiator_user_id')->nullable()->index();
            $table->string('release_path')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('pipeline_id')->nullable()->constrained('slipstream_pipelines')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        $this->createIfMissing('slipstream_deployment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained('slipstream_deployments')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $table->string('type', 32)->default('log'); // log|step_start|step_end|status
            $table->string('step')->nullable();
            $table->string('level', 16)->default('info'); // info|warning|error
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['deployment_id', 'sequence']);
        });

        $this->createIfMissing('slipstream_deployment_hooks', function (Blueprint $table) {
            $table->id();
            $table->string('step'); // e.g. composer, migrate, swap
            $table->string('position', 16); // before|after
            $table->string('name');
            $table->text('script');
            $table->boolean('enabled')->default(true);
            $table->json('server_names')->nullable(); // null = all
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $this->createIfMissing('slipstream_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->default('github');
            $table->string('delivery_id')->nullable()->index();
            $table->string('event')->nullable();
            $table->string('ref')->nullable();
            $table->string('sha', 64)->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->boolean('matched_pipeline')->default(false);
            $table->string('outcome', 32)->nullable(); // accepted|rejected|ignored|spawn_failed
            $table->text('outcome_detail')->nullable();
            $table->foreignId('deployment_id')->nullable()->constrained('slipstream_deployments')->nullOnDelete();
            $table->json('payload_meta')->nullable();
            $table->timestamps();
        });

        $this->createIfMissing('slipstream_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->timestamps();
        });

        $this->createIfMissing('slipstream_backups', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32); // db|storage|full
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slipstream_backups');
        Schema::dropIfExists('slipstream_settings');
        Schema::dropIfExists('slipstream_webhook_deliveries');
        Schema::dropIfExists('slipstream_deployment_hooks');
        Schema::dropIfExists('slipstream_deployment_events');
        Schema::dropIfExists('slipstream_deployments');
        Schema::dropIfExists('slipstream_pipelines');
        Schema::dropIfExists('slipstream_servers');
    }
};
