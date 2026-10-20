<?php

namespace Slipstream\Release\Core;

/**
 * Unpacks a release tarball the way a node must: regular files and
 * directories only, every name a release-relative path, a byte and entry
 * budget enforced as it goes, nothing written through a symlink, nothing
 * overwritten, and every file hashed while it is written and compared with
 * the manifest. Anything else refuses the archive.
 */
final class TarReader
{
    public const BLOCK = 512;

    /**
     * @param  array{max_unpacked_bytes?: int, max_entries?: int}  $limits
     * @param  array<string, array{0: string, 1: int}>|null  $expected  the manifest's files; when given, every file must be in it and match, and all of them must be present
     * @return array<string, array{0: string, 1: int}>  the files extracted, release-relative, each with its SHA-256 and size, in archive order
     *
     * @throws ArtifactRefused
     */
    public static function extract(string $tarGz, string $into, array $limits = [], ?array $expected = null): array
    {
        $maxBytes = $limits['max_unpacked_bytes'] ?? PHP_INT_MAX;
        $maxEntries = $limits['max_entries'] ?? PHP_INT_MAX;

        $gz = gzopen($tarGz, 'rb');
        if ($gz === false) {
            throw new ArtifactRefused('archive', 'the archive cannot be opened');
        }

        $extracted = [];
        $bytes = 0;
        $entries = 0;
        $longName = null;
        $created = [];

        try {
            while (true) {
                $header = self::readBlock($gz);
                if ($header === null) {
                    throw new ArtifactRefused('archive', 'the archive ends without an end-of-archive marker');
                }
                if ($header === str_repeat("\0", self::BLOCK)) {
                    $second = self::readBlock($gz);
                    if ($second !== str_repeat("\0", self::BLOCK)) {
                        throw new ArtifactRefused('archive', 'the archive ends without an end-of-archive marker');
                    }
                    while (($rest = gzread($gz, 65536)) !== false && $rest !== '') {
                        if (trim($rest, "\0") !== '') {
                            throw new ArtifactRefused('archive', 'data follows the end-of-archive marker');
                        }
                    }
                    break;
                }

                $fields = self::parseHeader($header);
                $type = $fields['type'];
                $size = $fields['size'];
                $name = $longName ?? $fields['name'];
                $longName = null;

                if (++$entries > $maxEntries) {
                    throw new ArtifactRefused('archive', "more than {$maxEntries} entries");
                }

                if ($type === 'L') {
                    if ($size < 1 || $size > Manifest::MAX_PATH + 1) {
                        throw new ArtifactRefused('archive', 'a long-name entry of an impossible size');
                    }
                    $longName = rtrim(self::readData($gz, $size), "\0");

                    continue;
                }

                if ($type !== '0' && $type !== "\0" && $type !== '5') {
                    throw new ArtifactRefused('archive', 'refusing '.self::describeType($type).' entry "'.self::printable($name).'"');
                }

                $name = rtrim($name, '/');
                if (! Manifest::validPath($name)) {
                    throw new ArtifactRefused('archive', 'refusing entry name "'.self::printable($name).'"');
                }

                if ($type === '5') {
                    self::ensureDirectory($into, $name, $created);
                    self::skipData($gz, $size);

                    continue;
                }

                $bytes += $size;
                if ($bytes > $maxBytes) {
                    throw new ArtifactRefused('archive', "unpacked size exceeds {$maxBytes} bytes");
                }
                if ($expected !== null && ! isset($expected[$name])) {
                    throw new ArtifactRefused('files', 'the archive carries a file the manifest does not list: '.self::printable($name));
                }
                if ($expected !== null && $expected[$name][1] !== $size) {
                    throw new ArtifactRefused('files', self::printable($name).' is '.$size.' bytes; the manifest says '.$expected[$name][1]);
                }

                $parent = dirname($name);
                if ($parent !== '.') {
                    self::ensureDirectory($into, $parent, $created);
                }

                $target = $into.'/'.$name;
                if (is_link($target) || file_exists($target)) {
                    throw new ArtifactRefused('archive', 'the archive lists '.self::printable($name).' twice');
                }
                $out = @fopen($target, 'xb');
                if ($out === false) {
                    throw new ArtifactRefused('archive', 'cannot write '.self::printable($name));
                }

                $hash = hash_init('sha256');
                $remaining = $size;
                while ($remaining > 0) {
                    $chunk = gzread($gz, min(65536, $remaining));
                    if ($chunk === false || $chunk === '') {
                        fclose($out);
                        throw new ArtifactRefused('archive', 'the archive is truncated inside '.self::printable($name));
                    }
                    hash_update($hash, $chunk);
                    fwrite($out, $chunk);
                    $remaining -= strlen($chunk);
                }
                fclose($out);
                self::skipData($gz, ($size % self::BLOCK === 0) ? 0 : self::BLOCK - ($size % self::BLOCK));
                @chmod($target, ($fields['mode'] & 0100) ? 0755 : 0644);

                $digest = hash_final($hash);
                if ($expected !== null && $expected[$name][0] !== $digest) {
                    throw new ArtifactRefused('files', self::printable($name).' does not match the manifest', null, ['expected' => $expected[$name][0], 'actual' => $digest]);
                }

                $extracted[$name] = [$digest, $size];
            }
        } finally {
            gzclose($gz);
        }

        if ($expected !== null) {
            $missing = array_diff(array_keys($expected), array_keys($extracted));
            if ($missing !== []) {
                throw new ArtifactRefused('files', count($missing).' file(s) the manifest lists are not in the archive: '.implode(', ', array_slice(array_values($missing), 0, 5)));
            }
        }

        return $extracted;
    }

    /**
     * @param  resource  $gz
     */
    private static function readBlock($gz): ?string
    {
        $block = self::readData($gz, self::BLOCK, false);

        return $block === '' ? null : $block;
    }

    /**
     * @param  resource  $gz
     */
    private static function readData($gz, int $size, bool $strict = true): string
    {
        $data = '';
        while (strlen($data) < $size) {
            $chunk = gzread($gz, $size - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        if ($strict && strlen($data) !== $size) {
            throw new ArtifactRefused('archive', 'the archive is truncated');
        }
        if (! $strict && $data !== '' && strlen($data) !== $size) {
            throw new ArtifactRefused('archive', 'the archive is truncated');
        }

        if ($size % self::BLOCK !== 0 && $strict) {
            self::skipData($gz, self::BLOCK - ($size % self::BLOCK));
        }

        return $data;
    }

    /**
     * @param  resource  $gz
     */
    private static function skipData($gz, int $size): void
    {
        while ($size > 0) {
            $chunk = gzread($gz, min(65536, $size));
            if ($chunk === false || $chunk === '') {
                throw new ArtifactRefused('archive', 'the archive is truncated');
            }
            $size -= strlen($chunk);
        }
    }

    /**
     * @return array{name: string, mode: int, size: int, type: string}
     */
    private static function parseHeader(string $header): array
    {
        if (substr($header, 257, 5) !== 'ustar') {
            throw new ArtifactRefused('archive', 'not a ustar archive');
        }

        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ($i >= 148 && $i < 156) ? 32 : ord($header[$i]);
        }
        if ($sum !== self::octal(substr($header, 148, 8))) {
            throw new ArtifactRefused('archive', 'a header checksum does not match');
        }

        $name = rtrim(substr($header, 0, 100), "\0");
        $prefix = rtrim(substr($header, 345, 155), "\0");

        return [
            'name' => $prefix !== '' ? $prefix.'/'.$name : $name,
            'mode' => self::octal(substr($header, 100, 8)),
            'size' => self::octal(substr($header, 124, 12)),
            'type' => $header[156],
        ];
    }

    private static function octal(string $field): int
    {
        $field = trim($field, "\0 ");

        if ($field !== '' && preg_match('/^[0-7]+$/', $field) !== 1) {
            throw new ArtifactRefused('archive', 'a header field is not octal');
        }

        return $field === '' ? 0 : (int) octdec($field);
    }

    /**
     * Create a directory one component at a time, never through a symlink.
     *
     * @param  array<string, bool>  $created
     */
    private static function ensureDirectory(string $into, string $relative, array &$created): void
    {
        $path = $into;
        $walked = '';

        foreach (explode('/', $relative) as $segment) {
            $walked = $walked === '' ? $segment : $walked.'/'.$segment;
            $path .= '/'.$segment;

            if (isset($created[$walked])) {
                continue;
            }
            if (is_link($path)) {
                throw new ArtifactRefused('archive', 'refusing to write through the symlink '.self::printable($walked));
            }
            if (file_exists($path)) {
                if (! is_dir($path)) {
                    throw new ArtifactRefused('archive', self::printable($walked).' is in the way of a directory');
                }
            } elseif (! @mkdir($path, 0755)) {
                throw new ArtifactRefused('archive', 'cannot create the directory '.self::printable($walked));
            }
            @chmod($path, 0755);
            $created[$walked] = true;
        }
    }

    private static function describeType(string $type): string
    {
        return match ($type) {
            '1' => 'a hard link',
            '2' => 'a symlink',
            '3', '4' => 'a device',
            '6' => 'a FIFO',
            '7' => 'a contiguous-file',
            'x', 'g' => 'a pax header',
            default => 'a type '.bin2hex($type),
        };
    }

    private static function printable(string $name): string
    {
        return mb_strimwidth(preg_replace('/[^\x20-\x7e]/', '?', $name) ?? '', 0, 200, '…');
    }
}
