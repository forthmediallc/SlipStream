<?php

namespace Slipstream\Release;

use RuntimeException;
use Slipstream\Slipstream;
use Symfony\Component\Process\Process;

/**
 * Resolve the deploy ref with `git ls-remote` against the mirror's origin:
 * the same remote, key and user every fetch already uses, and no clone.
 */
class GitRefSource implements ReleaseHistory, ReleaseSource
{
    public function latest(DeployRef $ref): ReleaseRef
    {
        // A branch or one tag is asked for by name; a pattern needs the tag
        // list, filtered here rather than by git's own pattern rules.
        $args = $ref->kind === DeployRef::TAGS
            ? ['ls-remote', '--tags', 'origin']
            : ['ls-remote', 'origin', $ref->full(), $ref->full().'^{}'];

        return self::pick(
            $ref,
            $this->git($args, 'Cannot read '.$ref->describe().' from origin'),
            (bool) config('slipstream.trigger.prereleases', false)
        );
    }

    public function contains(DeployRef $ref, ReleaseRef $head, string $sha): bool
    {
        $sha = strtolower(trim($sha));

        // The value comes from the shared store. Only a commit id may reach
        // git's argv: anything else (an option, a ref name) is refused.
        if (preg_match('/^[0-9a-f]{40}(?:[0-9a-f]{24})?$/', $sha) !== 1) {
            return false;
        }

        if ($ref->kind === DeployRef::TAG) {
            return $sha === strtolower($head->sha);
        }

        if ($ref->kind === DeployRef::BRANCH) {
            // Exit 0: an ancestor (or the tip itself). 1: not one. Anything
            // else, such as a commit the mirror lacks, is no either.
            $process = $this->process(['merge-base', '--is-ancestor', $sha, strtolower($head->sha)]);
            $process->run();

            return $process->getExitCode() === 0;
        }

        $tags = $this->git(['ls-remote', '--tags', 'origin'], 'Cannot list the tags on origin');

        return in_array($sha, self::taggedCommits($ref, $tags, (bool) config('slipstream.trigger.prereleases', false)), true);
    }

    /**
     * Choose the release from `ls-remote` output. An annotated tag's own
     * object id is not a commit; the peeled `^{}` line is, and wins.
     */
    public static function pick(DeployRef $ref, string $output, bool $prereleases = false): ReleaseRef
    {
        $commits = self::commits($output);

        if ($ref->kind !== DeployRef::TAGS) {
            $sha = $commits[$ref->full()] ?? null;
            if ($sha === null) {
                throw new RuntimeException(ucfirst($ref->describe()).' does not exist on origin.');
            }

            return new ReleaseRef($sha, $ref->name, $ref->isTag());
        }

        $best = null;
        foreach (self::matching($ref, $commits, $prereleases) as $candidate) {
            if ($best === null || version_compare($candidate['version'], $best['version'], '>')) {
                $best = $candidate;
            }
        }

        if ($best === null) {
            throw new RuntimeException('No tag on origin matches '.$ref->name.($prereleases ? '' : ' (pre-releases skipped)').'.');
        }

        return new ReleaseRef($best['sha'], $best['tag'], true);
    }

    /**
     * The commits that the tags a pattern matches point at, under the same
     * rules pick() chooses by: version tags only, pre-releases only when
     * allowed.
     *
     * @return list<string>
     */
    public static function taggedCommits(DeployRef $ref, string $output, bool $prereleases = false): array
    {
        return array_values(array_unique(array_column(self::matching($ref, self::commits($output), $prereleases), 'sha')));
    }

    /**
     * Every ref in `ls-remote` output and the commit it names, in the order
     * listed: the peeled commit for an annotated tag.
     *
     * @return array<string, string>
     */
    protected static function commits(string $output): array
    {
        $commits = [];
        $peeled = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{40}(?:[0-9a-f]{24})?)\s+(\S+)$/i', trim($line), $m) !== 1) {
                continue;
            }

            if (str_ends_with($m[2], '^{}')) {
                $name = substr($m[2], 0, -3);
                $commits[$name] = strtolower($m[1]);
                $peeled[$name] = true;
            } elseif (! isset($peeled[$m[2]])) {
                $commits[$m[2]] = strtolower($m[1]);
            }
        }

        return $commits;
    }

    /**
     * @param  array<string, string>  $commits
     * @return list<array{tag: string, version: string, sha: string}>
     */
    protected static function matching(DeployRef $ref, array $commits, bool $prereleases): array
    {
        $matching = [];

        foreach ($commits as $full => $sha) {
            if (! str_starts_with($full, 'refs/tags/')) {
                continue;
            }

            $tag = substr($full, strlen('refs/tags/'));
            if (! fnmatch($ref->name, $tag) || ($version = self::version($tag)) === null) {
                continue;
            }

            if (! $prereleases && self::isPrerelease($version)) {
                continue;
            }

            $matching[] = ['tag' => $tag, 'version' => $version, 'sha' => $sha];
        }

        return $matching;
    }

    /**
     * The version a tag names, or null for a tag that is not a version
     * (a pattern like v* also matches "vault-archive").
     */
    protected static function version(string $tag): ?string
    {
        $version = preg_replace('#^.*/#', '', $tag) ?? $tag;
        $version = ltrim($version, 'vV');

        return preg_match('/^\d+(\.\d+)*([.-].*)?$/', $version) === 1 ? $version : null;
    }

    protected static function isPrerelease(string $version): bool
    {
        return preg_match('/-|alpha|beta|rc|dev|pre/i', $version) === 1;
    }

    /**
     * @param  list<string>  $args
     */
    protected function process(array $args): Process
    {
        $mirror = Slipstream::appRoot().DIRECTORY_SEPARATOR.'repo.git';

        if (! is_dir($mirror)) {
            throw new RuntimeException("No bare mirror at {$mirror}. Run php artisan slipstream:bootstrap (or slipstream:provision).");
        }

        $process = new Process(array_merge(['git', '--git-dir='.$mirror], $args), null, ['GIT_TERMINAL_PROMPT' => '0']);
        $process->setTimeout(30);

        return $process;
    }

    /**
     * @param  list<string>  $args
     */
    protected function git(array $args, string $what): string
    {
        $process = $this->process($args);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: 'git '.$args[0].' exited '.$process->getExitCode();

            throw new RuntimeException($what.': '.$error);
        }

        return $process->getOutput();
    }
}
