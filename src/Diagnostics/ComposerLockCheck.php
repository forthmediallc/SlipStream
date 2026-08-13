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
     *
     * The `./` prefix matters: `HEAD:composer.lock` resolves relative to the
     * top of the working tree, so in a monorepo whose app lives in a
     * subdirectory it would read some other package's lockfile (or none). With
     * `./` it resolves relative to $cwd, matching composerFilesDirty()'s
     * pathspec, which is cwd-relative already.
     */
    public function committedLockHasPackage(string $package = self::PACKAGE): ?bool
    {
        return self::lockLists($this->git(['show', 'HEAD:./composer.lock']), $package);
    }

    /**
     * Whether the lockfile the NEXT DEPLOY will install lists the package.
     *
     * A deploy builds from the bare mirror at the configured branch, never from
     * a local checkout, so the local HEAD answers a different question: a commit
     * that has not been pushed passes the local check and still deploys a
     * release without the package. Null when the mirror or the ref cannot be
     * read (not provisioned yet, branch never pushed).
     *
     * @return array{0: ?bool, 1: string}  [answer, the ref that was read]
     */
    public function mirrorLockHasPackage(string $mirror, string $branch, string $package = self::PACKAGE): array
    {
        $ref = 'refs/heads/'.$branch;

        return [
            self::lockLists($this->git(['--git-dir='.$mirror, 'show', $ref.':composer.lock']), $package),
            $ref,
        ];
    }

    /**
     * Whether a composer.lock body lists the package where a deploy can see
     * it. Null when there is no readable, parseable lockfile to answer with.
     *
     * Only the `packages` section counts: deploys run `composer install
     * --no-dev`, so a package that lives in `packages-dev` never reaches the
     * release — reporting it as present would bless exactly the lockfile the
     * deploy then fails on.
     */
    public static function lockLists(?string $lock, string $package = self::PACKAGE): ?bool
    {
        if ($lock === null) {
            return null;
        }

        $data = json_decode($lock, true);
        if (! is_array($data)) {
            return null;
        }

        foreach ($data['packages'] ?? [] as $entry) {
            if (($entry['name'] ?? null) === $package) {
                return true;
            }
        }

        return false;
    }

    /**
     * The message that names the real problem, shared by install and deploy.
     */
    public static function commitFirstMessage(): string
    {
        return 'Commit composer.json + composer.lock and push before deploying — the release is built '
            .'from the mirrored git branch, not from this directory. If the package sits in require-dev, '
            .'move it to require: deploys run composer install --no-dev.';
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
