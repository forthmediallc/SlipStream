<?php

namespace Slipstream\Release\Core;

/**
 * The decompressed bytes of a release tarball, counted. Every byte the
 * reader takes — headers, file data, padding, long names, what follows the
 * end marker — goes through here, so a small archive cannot be made to
 * inflate past what the release and its framing need.
 */
final class TarStream
{
    private int $read = 0;

    /**
     * @param  resource  $gz
     */
    public function __construct(private $gz, private int $cap) {}

    /**
     * Up to $size bytes; fewer only at the end of the stream.
     */
    public function read(int $size): string
    {
        $data = '';
        while (strlen($data) < $size) {
            $chunk = gzread($this->gz, min(65536, $size - strlen($data)));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $this->count(strlen($chunk));
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * Exactly $size bytes, or the archive is truncated.
     */
    public function readExactly(int $size): string
    {
        $data = $this->read($size);
        if (strlen($data) !== $size) {
            throw new ArtifactRefused('archive', 'the archive is truncated');
        }

        return $data;
    }

    public function skip(int $size): void
    {
        while ($size > 0) {
            $chunk = $this->read(min(65536, $size));
            if ($chunk === '') {
                throw new ArtifactRefused('archive', 'the archive is truncated');
            }
            $size -= strlen($chunk);
        }
    }

    public function atEnd(): bool
    {
        return $this->read(1) === '';
    }

    private function count(int $bytes): void
    {
        $this->read += $bytes;
        if ($this->read > $this->cap) {
            throw new ArtifactRefused('archive', "the archive inflates past {$this->cap} bytes, more than the release and its headers need");
        }
    }
}
