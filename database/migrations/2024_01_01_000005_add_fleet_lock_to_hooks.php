<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Slipstream\Database\SlipstreamMigration;

return new class extends SlipstreamMigration
{
    public function up(): void
    {
        Schema::table('slipstream_deployment_hooks', function (Blueprint $table) {
            if (! Schema::hasColumn('slipstream_deployment_hooks', 'fleet_lock')) {
                // When on, only the first server to grab the fleet-wide lock for a
                // given commit runs this hook (the rest skip). This is how the
                // default "Run migrations" hook stays safe across a multi-server
                // fleet without a schema race.
                $table->boolean('fleet_lock')->default(false)->after('script');
            }
        });
    }

    public function down(): void
    {
        Schema::table('slipstream_deployment_hooks', function (Blueprint $table) {
            if (Schema::hasColumn('slipstream_deployment_hooks', 'fleet_lock')) {
                $table->dropColumn('fleet_lock');
            }
        });
    }
};
