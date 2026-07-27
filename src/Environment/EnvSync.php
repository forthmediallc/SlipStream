<?php

namespace Slipstream\Environment;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Slipstream\Deploy\ProcessDetacher;
use Slipstream\Models\EnvPush;
use Slipstream\Models\EnvSnapshot;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;

/**
 * Scoped multi-server .env updates. The shared-DB EnvPush rows are the source of
 * truth: each node applies its own pending push during slipstream:heartbeat (or
 * an instant signed nudge), never mid-web-request. Snapshots publish each node's
 * file (encrypted) so the dashboard can view/edit any server and detect drift.
 */
class EnvSync
{
    /**
     * Domain-separation tag for env-sync nudges — distinct from the relay tag so
     * a signature minted for one channel can never verify on the other.
     */
    public const SIGNING_VERSION = 'slipstream.envsync.v1';

    /**
     * Safe fallback when the config lacks per_server_keys — e.g. an install that
     * published config/slipstream.php before this feature (mergeConfigFrom is a
     * shallow merge, so a stale published `environment` block omits the key). An
     * empty list here would silently broadcast per-server identities across the
     * fleet, so we fall back to these rather than to "no protected keys".
     *
     * @var list<string>
     */
    public const DEFAULT_PER_SERVER_KEYS = [
        'SLIPSTREAM_SERVER',
        'SLIPSTREAM_APP_ROOT',
        'SLIPSTREAM_RESTART_HORIZON',
        'SLIPSTREAM_RESTART_REVERB',
    ];

    public function __construct(
        protected EnvEditor $editor,
        protected EnvContentMerger $merger,
        protected ProcessDetacher $detacher,
    ) {}

    /**
     * Master switch: config toggle plus the tables actually existing (so a
     * new-code node before its migration ran, or an operator who opted out,
     * cleanly no-ops the whole feature).
     */
    public static function enabled(): bool
    {
        if (! filter_var(config('slipstream.environment.sync_enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        try {
            return Schema::hasTable('slipstream_env_pushes')
                && Schema::hasTable('slipstream_env_snapshots');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Canonical content hash: normalized exactly like EnvEditor::write() (CRLF→LF,
     * trailing newline) so a written file hashes identically to the content that
     * produced it, cross-OS. This is the optimistic-concurrency primitive.
     */
    public static function hashContents(string $contents): string
    {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        if (! str_ends_with($contents, "\n")) {
            $contents .= "\n";
        }

        return hash('sha256', $contents);
    }

    /**
     * Display-only hash with per-server key lines removed, so two nodes that
     * differ only by protected keys don't read as drift.
     *
     * @param  list<string>  $protectedKeys
     */
    public static function comparableHash(string $contents, array $protectedKeys): string
    {
        $merger = new EnvContentMerger;
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $keep = [];
        foreach (explode("\n", $contents) as $line) {
            if ($merger->keyOf($line, $protectedKeys) === null) {
                $keep[] = $line;
            }
        }

        return self::hashContents(implode("\n", $keep));
    }

    /**
     * Queue a push to each target server. The local node applies inline; remote
     * nodes get a pending row plus a best-effort nudge (heartbeat is the backstop).
     *
     * @param  list<string>  $targets  server names
     * @param  array{preserve_keys?: bool, base_hashes?: array<string, ?string>, user_id?: ?int, note?: ?string}  $opts
     * @return array{batch: string, queued: int, local: ?EnvPush}
     */
    public function queue(array $targets, string $contents, array $opts = []): array
    {
        $batch = (string) Str::uuid();
        $preserveKeys = $opts['preserve_keys'] ?? true;
        $baseHashes = $opts['base_hashes'] ?? [];
        $userId = $opts['user_id'] ?? null;
        $note = $opts['note'] ?? null;
        $self = Slipstream::serverName();

        $targets = array_values(array_unique(array_filter(array_map('trim', $targets))));

        $local = null;
        $queued = 0;
        $hasRemote = false;

        foreach ($targets as $target) {
            // Last save wins: retire this server's still-pending rows.
            EnvPush::query()
                ->where('server_name', $target)
                ->where('status', EnvPush::STATUS_PENDING)
                ->update(['status' => EnvPush::STATUS_SUPERSEDED]);

            $push = EnvPush::query()->create([
                'batch' => $batch,
                'server_name' => $target,
                'contents' => $contents,
                'base_hash' => $baseHashes[$target] ?? null,
                'preserve_keys' => $preserveKeys,
                'status' => EnvPush::STATUS_PENDING,
                'user_id' => $userId,
                'note' => $note,
            ]);
            $queued++;

            if ($target === $self) {
                // Apply now, through the same claim+apply path remote nodes use.
                if ($this->claim($push, EnvPush::STATUS_PENDING)) {
                    $this->applyPush($push);
                }
                $local = $push->refresh();
            } else {
                $hasRemote = true;
            }
        }

        Activity::log('env.push_queued', [
            'batch' => $batch,
            'targets' => $targets,
            'preserve_keys' => $preserveKeys,
            'bytes' => strlen($contents),
        ]);

        // Instant nudge to peers with a base_url; a spawn failure is harmless
        // because each node's heartbeat applies its pending row within a minute.
        if ($hasRemote) {
            try {
                $this->detacher->spawnArtisan(['slipstream:env-nudge', '--batch='.$batch]);
            } catch (\Throwable $e) {
                Log::warning('Slipstream env-sync nudge spawn failed: '.$e->getMessage());
            }
        }

        return ['batch' => $batch, 'queued' => $queued, 'local' => $local];
    }

    /**
     * Claim and apply this node's latest pending push, recovering a stale
     * applying row if an earlier applier died. Returns the row it acted on, or
     * null when there was nothing claimable.
     */
    public function applyPending(): ?EnvPush
    {
        if (! self::enabled()) {
            return null;
        }

        $self = Slipstream::serverName();

        $push = EnvPush::query()
            ->where('server_name', $self)
            ->where('status', EnvPush::STATUS_PENDING)
            ->latest('id')
            ->first();
        $fromStatus = EnvPush::STATUS_PENDING;

        if (! $push) {
            // Recover a row whose applier died before finishing.
            $push = EnvPush::query()
                ->where('server_name', $self)
                ->where('status', EnvPush::STATUS_APPLYING)
                ->where('updated_at', '<', now()->subMinutes(5))
                ->latest('id')
                ->first();
            $fromStatus = EnvPush::STATUS_APPLYING;
        }

        if (! $push || ! $this->claim($push, $fromStatus)) {
            return null;
        }

        // Older pending rows for this server are now moot.
        EnvPush::query()
            ->where('server_name', $self)
            ->where('status', EnvPush::STATUS_PENDING)
            ->where('id', '<', $push->id)
            ->update(['status' => EnvPush::STATUS_SUPERSEDED]);

        $this->applyPush($push);

        return $push;
    }

    /**
     * Apply one already-claimed push to this node's shared .env. Terminal.
     */
    public function applyPush(EnvPush $push): void
    {
        try {
            // The encrypted cast decrypts on access; a rotated APP_KEY throws.
            try {
                $pushed = (string) $push->contents;
            } catch (\Throwable) {
                $this->fail($push, 'Cannot decrypt push contents — APP_KEY mismatch/rotation; align APP_KEY across the fleet and push again.');

                return;
            }

            $current = $this->editor->exists() ? $this->editor->read() : null;
            $final = $push->preserve_keys
                ? $this->merger->merge($pushed, $current ?? '', $this->perServerKeys())
                : $pushed;

            // Already applied (nudge replay, or a crash after the atomic rename):
            // the file already equals the final content — finish without rewriting.
            if ($current !== null && self::hashContents($current) === self::hashContents($final)) {
                $push->update([
                    'status' => EnvPush::STATUS_APPLIED,
                    'applied_at' => now(),
                    'error' => null,
                ]);
                $this->publishSnapshot();

                return;
            }

            // Optimistic concurrency: file changed since the push was composed.
            if ($push->base_hash !== null && $current !== null && self::hashContents($current) !== $push->base_hash) {
                $this->fail($push, 'The shared .env changed on this server after this push was composed — reload the Environment page and push again.');

                return;
            }

            $this->editor->write($final, $push->user_id, $push->note);
            $this->editor->invalidateConfigCache();

            Activity::log('env.saved', [
                'via' => 'push',
                'batch' => $push->batch,
                'bytes' => strlen($final),
                'queued_by' => $push->user_id,
            ]);

            $push->update([
                'status' => EnvPush::STATUS_APPLIED,
                'applied_at' => now(),
                'error' => null,
            ]);

            $this->publishSnapshot();
        } catch (\Throwable $e) {
            $this->fail($push, $e->getMessage());
        }
    }

    /**
     * Publish this node's shared .env as an encrypted snapshot, but only when the
     * content hash changed (or the stored blob no longer decrypts — APP_KEY
     * rotation self-heal). Never throws: a heartbeat must not break on this.
     */
    public function publishSnapshot(): void
    {
        try {
            if (! self::enabled() || ! $this->editor->exists()) {
                return;
            }

            $contents = $this->editor->read();
            $hash = self::hashContents($contents);
            $self = Slipstream::serverName();

            $snapshot = EnvSnapshot::query()->where('server_name', $self)->first();
            $needsWrite = $snapshot === null || $snapshot->hash !== $hash;

            if (! $needsWrite && $snapshot !== null) {
                // Same plaintext hash but verify the ciphertext still decrypts;
                // after an APP_KEY rotation the stored blob is dead and must be
                // rewritten under the new key.
                try {
                    $snapshot->contents;
                } catch (\Throwable) {
                    $needsWrite = true;
                }
            }

            if (! $needsWrite) {
                return;
            }

            EnvSnapshot::query()->updateOrCreate(
                ['server_name' => $self],
                ['contents' => $contents, 'hash' => $hash, 'captured_at' => now()],
            );
        } catch (\Throwable) {
            // Best-effort.
        }
    }

    /**
     * @return list<string>
     */
    public function perServerKeys(): array
    {
        // Only a missing key (null) falls back to the safe defaults; an operator
        // who deliberately configures an empty list is respected.
        $keys = config('slipstream.environment.per_server_keys');
        if ($keys === null) {
            $keys = self::DEFAULT_PER_SERVER_KEYS;
        }
        if (is_string($keys)) {
            $keys = explode(',', $keys);
        }

        return array_values(array_filter(array_map('trim', (array) $keys)));
    }

    /**
     * Atomically move a row from $fromStatus to applying so only one worker
     * (nudge-spawned apply vs. heartbeat) ever writes the file for it.
     */
    protected function claim(EnvPush $push, string $fromStatus): bool
    {
        $claimed = EnvPush::query()
            ->whereKey($push->getKey())
            ->where('status', $fromStatus)
            ->update(['status' => EnvPush::STATUS_APPLYING]);

        if ($claimed === 0) {
            return false;
        }

        $push->refresh();

        return true;
    }

    protected function fail(EnvPush $push, string $message): void
    {
        $push->update([
            'status' => EnvPush::STATUS_FAILED,
            'error' => mb_substr($message, 0, 1000),
        ]);

        Activity::log('env.push_failed', [
            'batch' => $push->batch,
            'server' => $push->server_name,
            'error' => mb_substr($message, 0, 500),
        ]);
    }
}
