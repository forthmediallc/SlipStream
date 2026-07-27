<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Queued .env pushes: the shared-DB source of truth for scoped env
        // updates. Each target server applies its own pending row on the next
        // heartbeat (or an instant signed nudge). Contents are encrypted at rest.
        Schema::create('slipstream_env_pushes', function (Blueprint $table) {
            $table->id();
            $table->string('batch', 36);          // groups one save across targets
            $table->string('server_name');        // target node
            $table->longText('contents');         // encrypted .env content pushed
            $table->string('base_hash', 64)->nullable(); // target file hash at compose time
            $table->boolean('preserve_keys')->default(true); // merge per-server keys on apply
            $table->string('status', 16)->default('pending'); // pending|applying|applied|failed|superseded|cancelled
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['server_name', 'status']);
            $table->index('batch');
        });

        // Encrypted per-node .env snapshots so the dashboard can view/edit any
        // server's file and detect drift. One row per server; rewritten only
        // when the content hash changes.
        Schema::create('slipstream_env_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('server_name')->unique();
            $table->longText('contents');   // encrypted .env content
            $table->string('hash', 64);     // sha256 of normalized content
            $table->timestamp('captured_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slipstream_env_pushes');
        Schema::dropIfExists('slipstream_env_snapshots');
    }
};
