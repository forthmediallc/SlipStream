<?php

namespace Slipstream\Release\Core;

/**
 * Unpacks a release tarball the way a node must: regular files and
 * directories only, every name a release-relative path, a byte and entry
 * budget enforced as it goes, nothing written through a symlink, nothing
 * overwritten, and every file hashed while it is written and compared with
 * the manifest. Anything else refuses the archive.
 *
 * It reads exactly what TarWriter writes and nothing looser: POSIX ustar
 * headers, size-0 directory entries, GNU long names, two zero blocks at the
 * end. Where other tar readers disagree with each other (a directory with a
 * size, a file named like a directory, a GNU header's prefix field), the
 * archive is refused rather than read one way here and another in review.
 */
final class TarReader
{
    public const BLOCK = 512;

    /** What a long name and a header may add to an entry, at most. */
    public const ENTRY_OVERHEAD = 6144;

    /** The end marker, plus the zero padding a record may carry after it. */
    public const TAIL = 11264;

    /**
     * @param  array{max_unpacked_bytes?: int, max_entries?: int}  $limits
     * @param  array<string, array{0: string, 1: int}>|null  $expected  the manifest's files; when given, every file must be in it and match, and all of them must be present
     * @return array<string, array{0: string, 1: int}>  the files extracted, release-relative, each with its SHA-256 and size, in archive order
     *
     * @throws ArtifactRefused  for an archive the node will not take
     * @throws \RuntimeException  when the disk is the problem (a write that does not complete)
     */
    public static function extract(string $tarGz, string $into, array $limits = [], ?array $expected = null): array
    {
        $maxBytes = $limits['max_unpacked_bytes'] ?? PHP_INT_MAX;
        $maxEntries = $limits['max_entries'] ?? PHP_INT_MAX;

        $gz = gzopen($tarGz, 'rb');
        if ($gz === false) {
            throw new ArtifactRefused('archive', 'the archive cannot be opened');
        }
        $stream = new TarStream($gz, self::readCap($maxBytes, $maxEntries));

        $extracted = [];
        $bytes = 0;
        $entries = 0;
        $longName = null;
        $created = [];

        try {
            while (true) {
                $header = $stream->read(self::BLOCK);
                if (strlen($header) !== self::BLOCK) {
                    throw new ArtifactRefused('archive', $header === '' ? 'the archive ends without an end-of-archive marker' : 'the archive is truncated');
                }
                if ($header === str_repeat("\0", self::BLOCK)) {
                    if ($longName !== null) {
                        throw new ArtifactRefused('archive', 'a long-name entry is not followed by the entry it names');
                    }
                    if ($stream->readExactly(self::BLOCK) !== str_repeat("\0", self::BLOCK)) {
                        throw new ArtifactRefused('archive', 'the archive ends without an end-of-archive marker');
                    }
                    self::checkTail($stream);
                    break;
                }

                $fields = self::parseHeader($header);
                $type = $fields['type'];
                $size = $fields['size'];

                if ($type === 'L') {
                    if ($longName !== null) {
                        throw new ArtifactRefused('archive', 'two long-name entries in a row');
                    }
                    if ($size < 1 || $size > Manifest::MAX_PATH + 1) {
                        throw new ArtifactRefused('archive', 'a long-name entry of an impossible size');
                    }
                    $longName = rtrim($stream->readExactly($size), "\0");
                    $stream->skip(self::padding($size));

                    continue;
                }

                $name = $longName ?? $fields['name'];
                $longName = null;

                if (++$entries > $maxEntries) {
                    throw new ArtifactRefused('archive', "more than {$maxEntries} entries");
                }

                if ($type !== '0' && $type !== "\0" && $type !== '5') {
                    throw new ArtifactRefused('archive', 'refusing '.self::describeType($type).' entry "'.self::printable($name).'"');
                }
                if ($type === '5' && $size !== 0) {
                    throw new ArtifactRefused('archive', 'refusing the directory entry "'.self::printable($name).'", which claims '.$size.' bytes of data');
                }
                if ($type !== '5' && str_ends_with($name, '/')) {
                    throw new ArtifactRefused('archive', 'refusing the file entry "'.self::printable($name).'", which is named like a directory');
                }

                $name = rtrim($name, '/');
                if (! Manifest::validPath($name)) {
                    throw new ArtifactRefused('archive', 'refusing entry name "'.self::printable($name).'"');
                }

                if ($type === '5') {
                    self::ensureDirectory($into, $name, $created);

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

                $digest = self::writeFile($stream, $target, $name, $size);
                $stream->skip(self::padding($size));
                @chmod($target, ($fields['mode'] & 0100) ? 0755 : 0644);

                if ($expected !== null && $expected[$name][0] !== $digest) {
                    throw new ArtifactRefused('files', self::printable($name).' does not match the manifest', null, ['expected' => $expected[$name][0], 'actual' => $digest]);
                }

                $extracted[$name] = [$digest, $size];
            }
        } finally {
            gzclose($gz);
        }

        if ($expected !== null) {
            $missing = array_diff(array_map('strval', array_keys($expected)), array_map('strval', array_keys($extracted)));
            if ($missing !== []) {
                throw new ArtifactRefused('files', count($missing).' file(s) the manifest lists are not in the archive: '.implode(', ', array_slice(array_values($missing), 0, 5)));
            }
        }

        return $extracted;
    }

    /**
     * How many decompressed bytes an archive within these limits can need.
     */
    public static function readCap(int $maxBytes, int $maxEntries): int
    {
        $cap = (float) $maxBytes + (float) $maxEntries * self::ENTRY_OVERHEAD + self::TAIL;

        return $cap >= PHP_INT_MAX ? PHP_INT_MAX : (int) $cap;
    }

    /**
     * Write one entry's data, hashing what goes to disk. A write that does
     * not complete (a full disk, a quota, a file-size limit) stops the
     * extract: the hash would otherwise vouch for bytes that never landed.
     */
    private static function writeFile(TarStream $stream, string $target, string $name, int $size): string
    {
        $out = @fopen($target, 'xb');
        if ($out === false) {
            throw new ArtifactRefused('archive', 'cannot write '.self::printable($name));
        }

        $hash = hash_init('sha256');
        $remaining = $size;
        try {
            while ($remaining > 0) {
                $chunk = $stream->read(min(65536, $remaining));
                if ($chunk === '') {
                    throw new ArtifactRefused('archive', 'the archive is truncated inside '.self::printable($name));
                }
                hash_update($hash, $chunk);
                self::writeAll($out, $chunk, $name);
                $remaining -= strlen($chunk);
            }
        } catch (\Throwable $e) {
            @fclose($out);

            throw $e;
        }

        if (! fclose($out)) {
            throw new \RuntimeException('Cannot finish writing '.self::printable($name).'; the disk may be full.');
        }
        clearstatcache(true, $target);
        $onDisk = @filesize($target);
        if ($onDisk !== $size) {
            throw new \RuntimeException(self::printable($name).' is '.var_export($onDisk, true)." bytes on disk, not {$size}: the write did not complete (is the disk full?).");
        }

        return hash_final($hash);
    }

    /**
     * @param  resource  $out
     */
    private static function writeAll($out, string $data, string $name): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = @fwrite($out, $offset === 0 ? $data : substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Cannot write '.self::printable($name).'; the disk may be full.');
            }
            $offset += $written;
        }
    }

    /**
     * After the end marker: zero padding up to a record, then nothing. The
     * rest is never inflated to look for more.
     */
    private static function checkTail(TarStream $stream): void
    {
        $padding = $stream->read(self::TAIL - 2 * self::BLOCK);
        if (trim($padding, "\0") !== '') {
            throw new ArtifactRefused('archive', 'data follows the end-of-archive marker');
        }
        if (strlen($padding) === self::TAIL - 2 * self::BLOCK && ! $stream->atEnd()) {
            throw new ArtifactRefused('archive', 'data follows the end-of-archive marker');
        }
    }

    private static function padding(int $size): int
    {
        return $size % self::BLOCK === 0 ? 0 : self::BLOCK - ($size % self::BLOCK);
    }

    /**
     * @return array{name: string, mode: int, size: int, type: string}
     */
    private static function parseHeader(string $header): array
    {
        // POSIX ustar only, as TarWriter writes it. A GNU header ("ustar  ")
        // uses the prefix field for other things, and readers disagree.
        if (substr($header, 257, 6) !== "ustar\0" || substr($header, 263, 2) !== '00') {
            throw new ArtifactRefused('archive', 'not a POSIX ustar header');
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
        $clean = preg_replace('/[^\x20-\x7e]/', '?', $name) ?? '';

        return strlen($clean) > 200 ? substr($clean, 0, 199).'...' : $clean;
    }
}
