<?php

namespace Slipstream\Tests\Support;

use Slipstream\Deploy\Deployer;
use Slipstream\Deploy\EventLogger;
use Slipstream\Deploy\ShellRunner;
use Slipstream\Models\Deployment;
use Slipstream\Settings\SettingsRepository;

/**
 * The real deployer's hook and migrate logic, with the fetch replaced by a
 * directory the test made and every command recorded instead of run.
 */
class RecordingDeployer extends Deployer
{
    public RecordingShellRunner $runner;

    public function __construct(Deployment $deployment)
    {
        $logger = new EventLogger($deployment);

        parent::__construct($deployment, app(SettingsRepository::class), $logger);

        $this->runner = new RecordingShellRunner($logger);
    }

    /**
     * Stand in for the fetch: the release is a directory the test wrote, and
     * its hooks file is loaded exactly as a real fetch would load it.
     */
    public function prepare(string $releasePath, string $sha): static
    {
        $this->releasePath = $releasePath;
        $this->sha = strtolower($sha);
        $this->loadFileHooks();

        return $this;
    }

    public function hooks(string $step, string $position): static
    {
        $this->runHooks($step, $position);

        return $this;
    }

    public function migrate(): static
    {
        $this->stepMigrate();

        return $this;
    }

    /**
     * @return list<string>
     */
    public function commands(): array
    {
        return array_column($this->runner->commands, 'command');
    }

    protected function shell(?string $cwd = null): ShellRunner
    {
        return $this->runner;
    }
}
