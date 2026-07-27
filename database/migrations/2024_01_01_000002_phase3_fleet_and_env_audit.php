<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slipstream_servers', function (Blueprint $table) {
            if (! Schema::hasColumn('slipstream_servers', 'base_url')) {
                $table->string('base_url')->nullable()->after('hostname');
            }
        });

        Schema::create('slipstream_env_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('action', 32); // save|backup
            $table->string('backup_path')->nullable();
            $table->unsignedInteger('bytes')->nullable();
            $table->string('server_name')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slipstream_env_audits');

        Schema::table('slipstream_servers', function (Blueprint $table) {
            if (Schema::hasColumn('slipstream_servers', 'base_url')) {
                $table->dropColumn('base_url');
            }
        });
    }
};
