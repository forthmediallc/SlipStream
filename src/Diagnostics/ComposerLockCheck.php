<?php

namespace Slipstream\Diagnostics;

use Symfony\Component\Process\Process;

/**
 * Answers whether the repo a deploy builds from actually contains Slipstream.
 *
 * Installing the package into the working directory does not put it in the
 * commit that gets deployed. A release built from a commit that predates the
 * install composer-installs the old lockfile, and the first thing to notice is
 * the health step failing with "There are no commands defined in the
 * slipstream namespace" — nowhere near the real cause.
 */
class ComposerLockCheck
{
    public const PACKAGE = 'slipstream/slipstream';

    public function __construct(
        protected string $cwd,
        protected int $timeout = 5
    ) {}

    public function isGitRepo(): bool
    {
        return $this->git(['rev-parse', '--is-inside-work-tree']) !== null;
    }

    /**
     * Whether composer.json / composer.lock have uncommitted changes.
     */
    public function composerFilesDirty(): bool
    {
        $status = $this->git(['status', '--porcelain', '--', 'composer.json', 'composer.lock']);

        return $status !== null && trim($status) !== '';
    }

    /**
     * Whether the committed lockfile lists the package. Null when the question
     * cannot be answered (no git, no commits, unreadable lockfile).
     */
    public function committedLockHasPackage(string $package = self::PACKAGE): ?bool
    {
        $lock = $this->git(['show', 'HEAD:composer.lock']);
        if ($lock === null) {
            return null;
        }

        $data = json_decode($lock, true);
        if (! is_array($data)) {
            return null;
        }

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($data[$section] ?? [] as $entry) {
                if (($entry['name'] ?? null) === $package) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The message that names the real problem, shared by install and deploy.
     */
    public static function commitFirstMessage(): string
    {
        return 'Commit composer.json + composer.lock before deploying — the release is built from git, '
            .'not from this directory.';
    }

    /**
     * Command stdout, or null when git is unavailable or the command failed.
     *
     * @param  list<string>  $args
     */
    protected function git(array $args): ?string
    {
        $process = new Process(['git', ...$args], $this->cwd);
        $process->setTimeout($this->timeout);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        return $process->isSuccessful() ? $process->getOutput() : null;
    }
}
