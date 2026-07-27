<?php

namespace Slipstream\Deploy;

use Slipstream\Models\Deployment;
use Slipstream\Models\DeploymentEvent;

class EventLogger
{
    protected int $sequence = 0;

    protected ?string $fileLogPath = null;

    public function __construct(
        protected Deployment $deployment
    ) {
        $maxSeq = DeploymentEvent::query()
            ->where('deployment_id', $deployment->id)
            ->max('sequence');
        $this->sequence = (int) $maxSeq;

        if (config('slipstream.logging.file_log', true)) {
            $this->openFileLog();
        }
    }

    public function info(string $message, ?string $step = null): void
    {
        $this->write('log', 'info', $message, $step);
    }

    public function warning(string $message, ?string $step = null): void
    {
        $this->write('log', 'warning', $message, $step);
    }

    public function error(string $message, ?string $step = null): void
    {
        $this->write('log', 'error', $message, $step);
    }

    public function stepStart(string $step, string $message = ''): void
    {
        $this->write('step_start', 'info', $message !== '' ? $message : "Starting {$step}…", $step);
    }

    public function stepEnd(string $step, string $message = ''): void
    {
        $this->write('step_end', 'info', $message !== '' ? $message : "Finished {$step}", $step);
    }

    public function line(string $line, string $level = 'info', ?string $step = null): void
    {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            return;
        }

        $max = (int) config('slipstream.logging.max_event_line_bytes', 8192);
        if (strlen($line) > $max) {
            $line = substr($line, 0, $max - 3).'...';
        }

        $this->write('log', $level, $line, $step);
    }

    protected function write(string $type, string $level, string $message, ?string $step): void
    {
        $this->sequence++;

        DeploymentEvent::query()->create([
            'deployment_id' => $this->deployment->id,
            'sequence' => $this->sequence,
            'type' => $type,
            'step' => $step,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);

        if ($this->fileLogPath) {
            $ts = date('H:i:s');
            file_put_contents(
                $this->fileLogPath,
                "[{$ts}] {$message}\n",
                FILE_APPEND
            );
        }
    }

    protected function openFileLog(): void
    {
        $root = \Slipstream\Slipstream::appRoot();
        $dir = $root.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'logs';
        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $this->fileLogPath = $dir.DIRECTORY_SEPARATOR
            .$this->deployment->id.'_'.date('Ymd_His').'.log';

        // Pre-create at 0600 instead of letting the first append inherit the
        // umask: this file holds raw hook output, which often includes secrets.
        if (! is_file($this->fileLogPath)) {
            @touch($this->fileLogPath);
            @chmod($this->fileLogPath, 0600);
        }
    }
}
