<?php

namespace Slipstream\Support;

/**
 * Resolves the sudo prefix used for service reloads.
 */
class Sudo
{
    /**
     * Always `-n` (non-interactive): a deploy has nowhere to type a password.
     * Webhook deploys run with stdin on /dev/null and would hang until the
     * ShellRunner timeout; an interactive deploy would stop mid-run on a
     * password prompt. Without a NOPASSWD rule this now fails immediately and
     * lands in the caller's "check sudoers/NOPASSWD" warning.
     */
    public static function prefix(): string
    {
        $configured = config('slipstream.deploy.use_sudo');

        if ($configured === true || $configured === '1' || $configured === 1 || $configured === 'true') {
            return 'sudo -n ';
        }
        if ($configured === false || $configured === '0' || $configured === 0 || $configured === 'false') {
            return '';
        }

        // Auto: sudo only when not root (Unix).
        if (DIRECTORY_SEPARATOR === '\\') {
            return '';
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return '';
        }

        return 'sudo -n ';
    }

    /**
     * Whether `sudo -n <command>` would be permitted without a password.
     *
     * Uses `sudo -l` (list) rather than running anything: it answers the
     * permission question for the exact command, which matters because the
     * shipped sudoers stub allowlists specific commands rather than a blanket
     * rule. Returns null when the answer is unknown (Windows, no sudo binary).
     *
     * `-k` matters as much as `-n`: without it, a cached sudo timestamp — from
     * the `sudo tee` that installed the sudoers file seconds earlier — lets
     * the probe succeed with no NOPASSWD rule at all. The real reload runs in
     * a fresh detached session where no timestamp exists, so the probe must
     * ask the question the way that session will.
     *
     * @param  list<string>  $command
     */
    public static function canRunWithoutPassword(array $command, int $timeout = 5): ?bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return null;
        }

        $probe = new \Symfony\Component\Process\Process(['sudo', '-k', '-n', '-l', ...$command]);
        $probe->setTimeout($timeout);

        try {
            $probe->run();
        } catch (\Throwable) {
            return null;
        }

        return $probe->isSuccessful();
    }

    /**
     * Absolute path to the sudoers stub shipped with the package.
     */
    public static function stubPath(): string
    {
        $path = dirname(__DIR__, 2).'/stubs/sudoers-slipstream';

        return realpath($path) ?: $path;
    }
}
