<?php

namespace Slipstream\Environment;

/**
 * Merges pushed .env content onto a target server's file while preserving that
 * server's per-server keys (fleet identity, per-node toggles). A fleet push is
 * a full-file replace, but broadcasting e.g. SLIPSTREAM_SERVER verbatim would
 * give every node the same identity — so for each protected key the target's
 * own line is kept.
 *
 * Rules:
 *  - A protected key present in the target keeps the target's line verbatim
 *    (last occurrence wins — dotenv precedence), emitted where the pushed file
 *    first mentions that key; any other pushed occurrences are dropped.
 *  - A protected key present only in the pushed content (target lacks it) is
 *    dropped — a per-server value must never be broadcast onto a node.
 *  - A protected key present only in the target (removed from the pushed base)
 *    is appended at EOF so the per-server value survives.
 *  - Every non-protected line, comment, and blank is taken from the pushed
 *    content verbatim.
 */
class EnvContentMerger
{
    /**
     * @param  list<string>  $protectedKeys
     */
    public function merge(string $pushed, string $target, array $protectedKeys): string
    {
        $protectedKeys = array_values(array_filter(array_map('trim', $protectedKeys)));
        if ($protectedKeys === []) {
            return $pushed;
        }

        $pushedLines = $this->lines($pushed);
        $targetLines = $this->lines($target);

        // Last assignment wins (dotenv precedence): a later line overrides an
        // earlier one for the same key.
        $targetProtected = [];
        foreach ($targetLines as $line) {
            $key = $this->keyOf($line, $protectedKeys);
            if ($key !== null) {
                $targetProtected[$key] = $line;
            }
        }

        $out = [];
        $seen = [];
        foreach ($pushedLines as $line) {
            $key = $this->keyOf($line, $protectedKeys);
            if ($key === null) {
                $out[] = $line;

                continue;
            }

            if (isset($seen[$key])) {
                // A later pushed occurrence of an already-handled protected key.
                continue;
            }
            $seen[$key] = true;

            // Keep the target's own value; drop a broadcast value the target lacks.
            if (array_key_exists($key, $targetProtected)) {
                $out[] = $targetProtected[$key];
            }
        }

        // Protected keys the target has but the pushed base omitted: keep them.
        foreach ($protectedKeys as $key) {
            if (array_key_exists($key, $targetProtected) && ! isset($seen[$key])) {
                $out[] = $targetProtected[$key];
            }
        }

        return implode("\n", $out);
    }

    /**
     * The protected key a line assigns, or null. Matches `KEY=`, `export KEY=`,
     * and leading whitespace; case-sensitive and anchored to the full key so
     * SLIPSTREAM_SERVER never matches SLIPSTREAM_SERVER_NAME.
     *
     * @param  list<string>  $keys
     */
    public function keyOf(string $line, array $keys): ?string
    {
        foreach ($keys as $key) {
            if ($key === '') {
                continue;
            }
            if (preg_match('/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=/', $line) === 1) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Split into lines on LF (after CRLF/CR normalization), dropping the single
     * trailing empty element a trailing newline produces so interior blanks are
     * preserved but EOF handling stays clean.
     *
     * @return list<string>
     */
    protected function lines(string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $content);
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return array_values($lines);
    }
}
