<?php

namespace Slipstream\Deploy;

/**
 * Per-server flock with push coalescing (port of deploy.sh lock + pending file).
 */
class DeployLock
{
    /** @var resource|null */
    protected $handle = null;

    protected string $lockPath;

    protected string $pendingPath;

    public function __construct(?string $appRoot = null)
    {
        $root = rtrim($appRoot ?? \Slipstream\Slipstream::appRoot(), DIRECTORY_SEPARATOR);
        $dir = $root.DIRECTORY_SEPARATOR.'deploy';
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $this->lockPath = $dir.DIRECTORY_SEPARATOR.'deploy.lock';
        $this->pendingPath = $dir.DIRECTORY_SEPARATOR.'pending';
    }

    /**
     * Try to acquire the exclusive lock. If busy, mark pending and return false.
     */
    public function acquire(): bool
    {
        $this->handle = fopen($this->lockPath, 'c+');
        if ($this->handle === false) {
            throw new \RuntimeException('Cannot open deploy lock: '.$this->lockPath);
        }

        if (! flock($this->handle, LOCK_EX | LOCK_NB)) {
            file_put_contents($this->pendingPath, (string) time());
            fclose($this->handle);
            $this->handle = null;

            return false;
        }

        return true;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function hasPending(): bool
    {
        return is_file($this->pendingPath);
    }

    public function clearPending(): void
    {
        if (is_file($this->pendingPath)) {
            @unlink($this->pendingPath);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
