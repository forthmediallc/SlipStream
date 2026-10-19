<?php

namespace Slipstream\Release;

use InvalidArgumentException;
use Slipstream\Support\GitRef;

/**
 * What a node deploys: a branch, one exact tag, or every tag matching a
 * pattern (the highest version wins). Releasing is then a deliberate act —
 * merging to the branch, or pushing a tag — which is the change-control
 * record a PCI assessor looks for (requirement 6.5.1).
 */
final class DeployRef
{
    public const BRANCH = 'branch';

    public const TAG = 'tag';

    public const TAGS = 'tags';

    private function __construct(
        public readonly string $kind,
        public readonly string $name,
    ) {}

    /**
     * SLIPSTREAM_DEPLOY_REF, else the deploy branch from config. Never the
     * dashboard's branch setting: in poll mode a node's database holds state,
     * not settings, so a fresh node follows exactly what an old one does.
     */
    public static function fromConfig(): self
    {
        $raw = trim((string) config('slipstream.trigger.ref', ''));

        return $raw === ''
            ? self::parse('refs/heads/'.trim((string) config('slipstream.deploy.branch', 'main')))
            : self::parse($raw);
    }

    /**
     * refs/heads/<branch> or a bare branch name; refs/tags/<tag> for one tag;
     * refs/tags/<pattern> or a bare pattern (branch names cannot contain
     * glob characters, so v* is unambiguous) for the newest matching tag.
     */
    public static function parse(string $raw): self
    {
        $raw = trim($raw);

        if (str_starts_with($raw, 'refs/heads/')) {
            return self::validated(self::BRANCH, substr($raw, strlen('refs/heads/')));
        }

        if (str_starts_with($raw, 'refs/tags/')) {
            $name = substr($raw, strlen('refs/tags/'));

            return self::validated(self::isPattern($name) ? self::TAGS : self::TAG, $name);
        }

        return self::validated(self::isPattern($raw) ? self::TAGS : self::BRANCH, $raw);
    }

    public function isTag(): bool
    {
        return $this->kind !== self::BRANCH;
    }

    /**
     * The full ref for a branch or an exact tag; the pattern for tags.
     */
    public function full(): string
    {
        return ($this->kind === self::BRANCH ? 'refs/heads/' : 'refs/tags/').$this->name;
    }

    public function describe(): string
    {
        return match ($this->kind) {
            self::BRANCH => "branch {$this->name}",
            self::TAG => "tag {$this->name}",
            default => "tags {$this->name} (highest version)",
        };
    }

    /**
     * Stable identity for keys in the shared store.
     */
    public function key(): string
    {
        return $this->kind.':'.$this->name;
    }

    private static function isPattern(string $name): bool
    {
        return strpbrk($name, '*?[') !== false;
    }

    private static function validated(string $kind, string $name): self
    {
        $valid = $kind === self::TAGS
            // A pattern only ever reaches fnmatch(), never git's argv; still,
            // keep it to the characters a tag pattern needs.
            ? preg_match('#^[A-Za-z0-9._/*?\[\]-]+$#', $name) === 1
            : GitRef::isValidName($name);

        if (! $valid) {
            throw new InvalidArgumentException("\"{$name}\" is not a usable deploy ref. Use a branch (production), a tag (refs/tags/v1.4.2) or a tag pattern (v*).");
        }

        return new self($kind, $name);
    }
}
