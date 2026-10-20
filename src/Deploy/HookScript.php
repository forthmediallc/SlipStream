<?php

namespace Slipstream\Deploy;

/**
 * What a hook script is to the shell that runs it. ShellRunner hands a string
 * script to `sh -c`, whose exit status is its LAST command's: in a script of
 * several lines a failing `npm run build` followed by `rm -rf node_modules`
 * passes. With `set -e` first, the first failure is the script's.
 */
final class HookScript
{
    public const FAIL_FAST = 'set -e';

    public static function normalize(?string $script): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $script));
    }

    /**
     * The lines that run: not empty, not comments.
     *
     * @return list<string>
     */
    public static function commandLines(string $script): array
    {
        $lines = [];

        foreach (explode("\n", self::normalize($script)) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public static function isMultiLine(string $script): bool
    {
        return count(self::commandLines($script)) > 1;
    }

    /**
     * Whether the first command turns errexit on: set -e, set -eu,
     * set -euo pipefail, set -o errexit.
     */
    public static function failsFast(string $script): bool
    {
        return self::isErrexit(self::commandLines($script)[0] ?? '');
    }

    /**
     * The script, failing fast: unchanged when it is one command or already
     * does, else with `set -e` first.
     */
    public static function withFailFast(string $script): string
    {
        $script = self::normalize($script);

        if (! self::isMultiLine($script) || self::failsFast($script)) {
            return $script;
        }

        return self::FAIL_FAST."\n".$script;
    }

    /**
     * The first line that does something besides `set …`: what a list preview
     * should show for a script.
     */
    public static function firstCommand(string $script): string
    {
        $lines = self::commandLines($script);

        foreach ($lines as $line) {
            if (preg_match('/^set(\s|$)/', $line) !== 1) {
                return $line;
            }
        }

        return $lines[0] ?? '';
    }

    /**
     * Substitute Envoyer-style placeholders, in both spellings: {{ php }} and
     * {{php}}. The binaries resolve to the configured php/npm/composer so a
     * default hook works even when they are not on PATH.
     *
     * @param  array<string, string>  $vars  name => value
     */
    public static function render(string $script, array $vars): string
    {
        $map = [];

        foreach ($vars as $name => $value) {
            $map['{{ '.$name.' }}'] = $value;
            $map['{{'.$name.'}}'] = $value;
        }

        return strtr($script, $map);
    }

    protected static function isErrexit(string $line): bool
    {
        if (preg_match('/^set\s+(.+)$/', $line, $m) !== 1) {
            return false;
        }

        $args = preg_split('/\s+/', trim($m[1])) ?: [];

        foreach ($args as $i => $arg) {
            if (preg_match('/^-[a-zA-Z]*e[a-zA-Z]*$/', $arg) === 1) {
                return true;
            }
            if ($arg === '-o' && ($args[$i + 1] ?? '') === 'errexit') {
                return true;
            }
        }

        return false;
    }
}
