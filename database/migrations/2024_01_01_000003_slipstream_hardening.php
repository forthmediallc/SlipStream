<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slipstream_deployments', function (Blueprint $table) {
            if (! Schema::hasColumn('slipstream_deployments', 'warnings')) {
                // Post-swap issues (e.g. FPM/Horizon reload failed) that don't
                // fail the deploy but must not hide behind a green "deployed".
                $table->text('warnings')->nullable()->after('failure_reason');
            }
        });

        Schema::table('slipstream_webhook_deliveries', function (Blueprint $table) {
            if (! Schema::hasColumn('slipstream_webhook_deliveries', 'server_name')) {
                // All nodes share one DB; record which node received a delivery
                // so the log is legible and idempotency can be scoped per node.
                $table->string('server_name')->nullable()->after('provider')->index();
            }
        });

        if (! Schema::hasTable('slipstream_activity_log')) {
            Schema::create('slipstream_activity_log', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('action', 64)->index();
                $table->string('subject')->nullable();
                $table->json('meta')->nullable();
                $table->string('server_name')->nullable();
                $table->timestamp('created_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('slipstream_activity_log');

        Schema::table('slipstream_webhook_deliveries', function (Blueprint $table) {
            if (Schema::hasColumn('slipstream_webhook_deliveries', 'server_name')) {
                // Drop the index before the column — SQLite errors otherwise.
                $table->dropIndex(['server_name']);
                $table->dropColumn('server_name');
            }
        });

        Schema::table('slipstream_deployments', function (Blueprint $table) {
            if (Schema::hasColumn('slipstream_deployments', 'warnings')) {
                $table->dropColumn('warnings');
            }
        });
    }
};
