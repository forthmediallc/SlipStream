<?php

namespace Slipstream\Support;

/**
 * Validation for branch / tag names before they are handed to git.
 *
 * Every git call is array-form (no shell), so the risk here is not command
 * injection but *argument* injection: a value that begins with `-` is parsed by
 * git as an option, and git accepts options after positional arguments. Today
 * that is not exploitable — `git fetch … tag --upload-pack=x --force` dies with
 * "you need to specify a tag name" before running anything — but the deploy
 * pipeline should not be one refactor away from it.
 *
 * Two levels, deliberately:
 *  - isSafeArgument() is the security backstop asserted just before a git call.
 *    It stays permissive (a pipeline's `release/*` fallback has to survive) and
 *    only rejects what could change how git interprets the argument.
 *  - isValidName() is the stricter check for a name a human typed into the
 *    dashboard, roughly `git check-ref-format --branch`.
 */
final class GitRef
{
    public const MAX_LENGTH = 255;

    /**
     * Safe to pass to git as a ref argument or inside a refspec.
     */
    public static function isSafeArgument(?string $ref): bool
    {
        if (! is_string($ref) || $ref === '' || mb_strlen($ref) > self::MAX_LENGTH) {
            return false;
        }

        // Leading dash = git reads it as an option, not a ref.
        if (str_starts_with($ref, '-')) {
            return false;
        }

        // Control characters, space, DEL, and the characters git itself forbids
        // in a ref name. Backslash is excluded so a Windows-style path can never
        // masquerade as a ref.
        if (preg_match('/[\x00-\x20\x7f~^:?\[\\\\]/', $ref) === 1) {
            return false;
        }

        // `..` and `@{` are rejected by git check-ref-format; both also carry
        // traversal/reflog meaning we never want from an external ref.
        return ! str_contains($ref, '..') && ! str_contains($ref, '@{');
    }

    /**
     * A concrete branch or tag name someone typed — no glob, no odd shape.
     */
    public static function isValidName(?string $ref): bool
    {
        if (! self::isSafeArgument($ref) || str_contains((string) $ref, '*')) {
            return false;
        }

        $ref = (string) $ref;

        if (str_starts_with($ref, '/') || str_ends_with($ref, '/') || str_contains($ref, '//')) {
            return false;
        }

        if (str_ends_with($ref, '.') || str_ends_with($ref, '.lock')) {
            return false;
        }

        foreach (explode('/', $ref) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.') || str_ends_with($segment, '.lock')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Laravel validation rule for a dashboard-entered branch / tag.
     */
    public static function rule(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            if (! self::isValidName(is_string($value) ? $value : null)) {
                $fail('The :attribute must be a valid git branch or tag name.');
            }
        };
    }
}
