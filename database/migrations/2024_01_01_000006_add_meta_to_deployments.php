<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Slipstream\Database\SlipstreamMigration;

return new class extends SlipstreamMigration
{
    public function up(): void
    {
        Schema::table('slipstream_deployments', function (Blueprint $table) {
            if (! Schema::hasColumn('slipstream_deployments', 'meta')) {
                // Per-deploy options from the dashboard modal (skip hooks, ref type, …).
                $table->json('meta')->nullable()->after('warnings');
            }
        });
    }

    public function down(): void
    {
        Schema::table('slipstream_deployments', function (Blueprint $table) {
            if (Schema::hasColumn('slipstream_deployments', 'meta')) {
                $table->dropColumn('meta');
            }
        });
    }
};
