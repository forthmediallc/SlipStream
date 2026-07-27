<?php

namespace Slipstream\Environment;

use RuntimeException;
use Slipstream\Models\EnvAudit;
use Slipstream\Slipstream;

/**
 * Guarded editor for the shared layout .env (not a release-local file).
 */
class EnvEditor
{
    public function path(): string
    {
        return Slipstream::sharedEnvPath();
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    public function read(): string
    {
        $path = $this->path();
        if (! is_file($path)) {
            throw new RuntimeException("Shared .env not found at {$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read {$path}");
        }

        return $contents;
    }

    /**
     * Write new contents after copying the previous file to a timestamped backup.
     *
     * @return array{backup_path: string, bytes: int}
     */
    public function write(string $contents, ?int $userId = null, ?string $note = null): array
    {
        $path = $this->path();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            throw new RuntimeException("App root directory missing: {$dir}");
        }

        // Normalize line endings to LF for Unix production boxes.
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        if (! str_ends_with($contents, "\n")) {
            $contents .= "\n";
        }

        $backupPath = null;
        if (is_file($path)) {
            $backupPath = $path.'.slipstream.bak.'.gmdate('Ymd_His');
            if (! @copy($path, $backupPath)) {
                throw new RuntimeException("Failed to backup existing .env to {$backupPath}");
            }
            @chmod($backupPath, 0600);
        }

        $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException("Failed to write temp env file {$tmp}");
        }
        @chmod($tmp, 0600);

        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to replace {$path}");
        }
        @chmod($path, 0600);

        $bytes = strlen($contents);

        EnvAudit::query()->create([
            'user_id' => $userId,
            'action' => 'save',
            'backup_path' => $backupPath,
            'bytes' => $bytes,
            'server_name' => Slipstream::serverName(),
            'note' => $note,
        ]);

        $this->pruneOldBackups($dir);

        return [
            'backup_path' => (string) $backupPath,
            'bytes' => $bytes,
        ];
    }

    /**
     * Best-effort config cache bust so next request picks up new env
     * when config is not fully cached, or after operator runs config:cache.
     */
    public function invalidateConfigCache(): void
    {
        $cached = base_path('bootstrap/cache/config.php');
        if (is_file($cached)) {
            @unlink($cached);
        }
    }

    protected function pruneOldBackups(string $dir): void
    {
        $keep = (int) config('slipstream.environment.backup_keep', 10);
        $files = glob($dir.DIRECTORY_SEPARATOR.'.env.slipstream.bak.*') ?: [];
        rsort($files);
        foreach (array_slice($files, max(1, $keep)) as $old) {
            @unlink($old);
        }
    }
}
