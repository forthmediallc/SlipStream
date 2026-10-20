<?php

namespace Slipstream\Release\Core;

/**
 * Writes a release tarball: ustar, gzip, and deterministic — entries sorted,
 * every mtime 0, every owner 0, modes 0644 or 0755 — so the same tree always
 * gives the same bytes and the archive's hash means something. Regular files
 * and directories only: a symlink in the tree is a build error, named.
 */
final class TarWriter
{
    public const BLOCK = 512;

    /** ustar's limit; a larger file needs a format nodes refuse. */
    public const MAX_FILE = 8589934591;

    /**
     * @param  list<string>  $files  release-relative paths of regular files, forward slashes
     * @return array{sha256: string, size: int, unpacked_bytes: int, entries: int}
     */
    public static function create(string $tarGz, string $root, array $files): array
    {
        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        $directories = [];
        foreach ($files as $file) {
            if (! Manifest::validPath($file)) {
                throw new \InvalidArgumentException("\"{$file}\" is not a release-relative path.");
            }
            $parts = explode('/', $file);
            array_pop($parts);
            for ($i = 1; $i <= count($parts); $i++) {
                $directories[implode('/', array_slice($parts, 0, $i))] = true;
            }
        }
        $directories = array_keys($directories);
        sort($directories, SORT_STRING);

        $gz = gzopen($tarGz, 'wb9');
        if ($gz === false) {
            throw new \RuntimeException("Cannot write {$tarGz}.");
        }

        $unpacked = 0;
        $entries = 0;

        try {
            foreach ($directories as $directory) {
                self::writeHeader($gz, $directory.'/', 0755, 0, '5');
                $entries++;
            }

            foreach ($files as $file) {
                $absolute = $root.'/'.$file;
                if (is_link($absolute)) {
                    throw new \RuntimeException("{$file} is a symlink; a release carries regular files only.");
                }
                if (! is_file($absolute)) {
                    throw new \RuntimeException("{$file} is not a regular file.");
                }
                $size = (int) filesize($absolute);
                if ($size > self::MAX_FILE) {
                    throw new \RuntimeException("{$file} is larger than a tar entry can be.");
                }

                $mode = (fileperms($absolute) & 0100) ? 0755 : 0644;
                self::writeHeader($gz, $file, $mode, $size, '0');

                $in = fopen($absolute, 'rb');
                if ($in === false) {
                    throw new \RuntimeException("Cannot read {$file}.");
                }
                $written = 0;
                while (! feof($in)) {
                    $chunk = fread($in, 65536);
                    if ($chunk === false) {
                        break;
                    }
                    gzwrite($gz, $chunk);
                    $written += strlen($chunk);
                }
                fclose($in);
                if ($written !== $size) {
                    throw new \RuntimeException("{$file} changed while being packed.");
                }
                self::pad($gz, $size);

                $unpacked += $size;
                $entries++;
            }

            gzwrite($gz, str_repeat("\0", self::BLOCK * 2));
        } finally {
            gzclose($gz);
        }

        return [
            'sha256' => hash_file('sha256', $tarGz),
            'size' => (int) filesize($tarGz),
            'unpacked_bytes' => $unpacked,
            'entries' => $entries,
        ];
    }

    /**
     * @param  resource  $gz
     */
    private static function writeHeader($gz, string $name, int $mode, int $size, string $type): void
    {
        [$short, $prefix] = self::split($name);

        if ($short === null) {
            // GNU long name: an 'L' entry carrying the path, then the real header.
            self::writeHeader($gz, '././@LongLink', 0, strlen($name) + 1, 'L');
            gzwrite($gz, $name."\0");
            self::pad($gz, strlen($name) + 1);
            $short = substr($name, 0, 100);
            $prefix = '';
        }

        $header = str_pad($short, 100, "\0")
            .sprintf("%07o\0", $mode)
            .sprintf("%07o\0", 0)
            .sprintf("%07o\0", 0)
            .sprintf("%011o\0", $size)
            .sprintf("%011o\0", 0)
            .'        '
            .$type
            .str_repeat("\0", 100)
            ."ustar\0"
            .'00'
            .str_repeat("\0", 32)
            .str_repeat("\0", 32)
            .sprintf("%07o\0", 0)
            .sprintf("%07o\0", 0)
            .str_pad($prefix, 155, "\0")
            .str_repeat("\0", 12);

        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ord($header[$i]);
        }
        $header = substr_replace($header, sprintf("%06o\0 ", $sum), 148, 8);

        gzwrite($gz, $header);
    }

    /**
     * Where ustar splits a long path into prefix and name, or [null, null]
     * when it cannot.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function split(string $name): array
    {
        if (strlen($name) <= 100) {
            return [$name, ''];
        }

        $position = strlen($name);
        while (($position = strrpos(substr($name, 0, $position), '/')) !== false) {
            $prefix = substr($name, 0, $position);
            $rest = substr($name, $position + 1);
            if (strlen($prefix) <= 155 && strlen($rest) <= 100 && $rest !== '') {
                return [$rest, $prefix];
            }
            if ($position === 0) {
                break;
            }
        }

        return [null, null];
    }

    /**
     * @param  resource  $gz
     */
    private static function pad($gz, int $size): void
    {
        $remainder = $size % self::BLOCK;
        if ($remainder !== 0) {
            gzwrite($gz, str_repeat("\0", self::BLOCK - $remainder));
        }
    }
}
