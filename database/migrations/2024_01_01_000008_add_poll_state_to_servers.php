<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Slipstream\Database\SlipstreamMigration;

return new class extends SlipstreamMigration
{
    /**
     * Poll mode: what each node last saw on the deploy ref and decided, and a
     * pin that holds a node on a commit (rollback, a deploy off the ref) until
     * it is resumed. Per node, so it lives on the server row.
     *
     * @var array<string, callable(Blueprint): void>
     */
    protected function columns(): array
    {
        return [
            'poll_checked_at' => fn (Blueprint $t) => $t->timestamp('poll_checked_at')->nullable(),
            'poll_next_at' => fn (Blueprint $t) => $t->timestamp('poll_next_at')->nullable(),
            'poll_result' => fn (Blueprint $t) => $t->string('poll_result', 32)->nullable(),
            'poll_detail' => fn (Blueprint $t) => $t->text('poll_detail')->nullable(),
            'poll_seen_sha' => fn (Blueprint $t) => $t->string('poll_seen_sha', 64)->nullable(),
            'poll_seen_ref' => fn (Blueprint $t) => $t->string('poll_seen_ref')->nullable(),
            'pinned_sha' => fn (Blueprint $t) => $t->string('pinned_sha', 64)->nullable(),
            'pinned_reason' => fn (Blueprint $t) => $t->string('pinned_reason')->nullable(),
            'pinned_at' => fn (Blueprint $t) => $t->timestamp('pinned_at')->nullable(),
        ];
    }

    public function up(): void
    {
        Schema::table('slipstream_servers', function (Blueprint $table) {
            foreach ($this->columns() as $column => $define) {
                if (! Schema::hasColumn('slipstream_servers', $column)) {
                    $define($table);
                }
            }
        });
    }

    public function down(): void
    {
        $present = array_values(array_filter(
            array_keys($this->columns()),
            fn (string $column) => Schema::hasColumn('slipstream_servers', $column)
        ));

        if ($present !== []) {
            Schema::table('slipstream_servers', fn (Blueprint $table) => $table->dropColumn($present));
        }
    }
};
