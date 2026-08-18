<?php

namespace Slipstream\Diagnostics;

/**
 * Answers "can THAT user write here?" for a user other than the one asking.
 *
 * is_writable() only ever answers for the current process, and as root it
 * says yes to everything. Doctor and provision routinely run as someone other
 * than the user that deploys — the FPM pool user, which dashboard and webhook
 * deploys spawn as — so the question has to be settled from owner, group and
 * mode instead. Only when the asker IS that user (and not root) is
 * is_writable() the better answer, since it also sees ACLs.
 */
class Ownership
{
    /**
     * Null when it cannot be answered (Windows, no such user, unreadable path).
     */
    public static function writableBy(string $path, string $user): ?bool
    {
        if (! function_exists('posix_getpwnam') || ! file_exists($path)) {
            return null;
        }

        if (FpmPool::currentUser() === $user && ! self::runningAsRoot()) {
            return is_writable($path);
        }

        $account = @posix_getpwnam($user);
        $perms = @fileperms($path);
        $uid = @fileowner($path);

        if (! is_array($account) || $perms === false || $uid === false) {
            return null;
        }

        if ($uid === $account['uid']) {
            return ($perms & 0200) === 0200;
        }

        $gid = @filegroup($path);
        if ($gid !== false && self::userInGroup($account, $gid)) {
            return ($perms & 0020) === 0020;
        }

        return ($perms & 0002) === 0002;
    }

    /**
     * Directories under (and including) $root that $user cannot write, bounded.
     *
     * Directories only, because that is what creating a file needs: git never
     * rewrites an existing object, so a root-owned FILE inside a deploy-owned
     * directory is harmless, while a root-owned `objects/ab/` DIRECTORY breaks
     * the first fetch that has to add an object there.
     *
     * @return list<string>  absolute paths, $root first if it is one of them
     */
    public static function unwritableDirectories(string $root, string $user, int $limit = 5): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $found = [];

        if (self::writableBy($root, $user) === false) {
            $found[] = $root;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $root,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME
                ),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $path) {
                if (count($found) >= $limit) {
                    break;
                }
                if (! is_link($path) && is_dir($path) && self::writableBy($path, $user) === false) {
                    $found[] = $path;
                }
            }
        } catch (\Throwable) {
            // An unreadable subtree is itself the finding; report what we have.
        }

        return array_slice($found, 0, $limit);
    }

    public static function runningAsRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /**
     * @param  array{name: string, gid: int}  $account
     */
    protected static function userInGroup(array $account, int $gid): bool
    {
        if (($account['gid'] ?? null) === $gid) {
            return true;
        }

        $group = function_exists('posix_getgrgid') ? @posix_getgrgid($gid) : false;

        return is_array($group) && in_array($account['name'], $group['members'] ?? [], true);
    }
}
