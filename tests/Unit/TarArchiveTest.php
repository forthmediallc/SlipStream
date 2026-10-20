<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Release\Core\ArtifactRefused;
use Slipstream\Release\Core\TarReader;
use Slipstream\Release\Core\TarWriter;
use Slipstream\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * The release archive: our own ustar writer and the bounded reader a node
 * unpacks with. Hostile archives are built by hand below.
 */
class TarArchiveTest extends TestCase
{
    protected string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-tar-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/tree', 0777, true);
        mkdir($this->base.'/out', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);

        parent::tearDown();
    }

    public function test_a_tree_round_trips_with_long_names_and_empty_files(): void
    {
        $long = str_repeat('directory-name/', 20).'file.txt';   // 303 characters: a GNU long-name entry
        $this->write('app/Models/User.php', 'class User {}');
        $this->write('public/index.php', 'index');
        $this->write('empty.txt', '');
        $this->write($long, 'deep');
        $this->write('bin/run', '#!/bin/sh');
        if (DIRECTORY_SEPARATOR !== '\\') {
            chmod($this->base.'/tree/bin/run', 0755);
        }
        $files = ['app/Models/User.php', 'public/index.php', 'empty.txt', $long, 'bin/run'];

        $info = TarWriter::create($this->base.'/release.tar.gz', $this->base.'/tree', $files);

        $this->assertSame(hash_file('sha256', $this->base.'/release.tar.gz'), $info['sha256']);
        $this->assertSame(13 + 5 + 0 + 4 + 9, $info['unpacked_bytes']);
        $this->assertSame(5 + 2 + 1 + 20 + 1, $info['entries'], 'five files and their directories');

        $extracted = TarReader::extract($this->base.'/release.tar.gz', $this->base.'/out', [], $this->expected($files));

        sort($files, SORT_STRING);
        $this->assertSame($files, array_keys($extracted));
        $this->assertSame([hash('sha256', 'deep'), 4], $extracted[$long], 'hashed and sized while written');
        foreach ($files as $file) {
            $this->assertSame(file_get_contents($this->base.'/tree/'.$file), file_get_contents($this->base.'/out/'.$file), $file);
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->assertSame(0755, fileperms($this->base.'/out/bin/run') & 0777);
            $this->assertSame(0644, fileperms($this->base.'/out/public/index.php') & 0777);
        }
    }

    public function test_the_output_is_byte_identical_across_runs(): void
    {
        $this->write('a.txt', 'a');
        $this->write('b/c.txt', 'c');

        TarWriter::create($this->base.'/one.tar.gz', $this->base.'/tree', ['b/c.txt', 'a.txt']);
        touch($this->base.'/tree/a.txt', time() - 86400);
        TarWriter::create($this->base.'/two.tar.gz', $this->base.'/tree', ['a.txt', 'b/c.txt']);

        $this->assertSame(file_get_contents($this->base.'/one.tar.gz'), file_get_contents($this->base.'/two.tar.gz'));
    }

    public function test_the_writer_refuses_symlinks_and_paths_outside_the_release(): void
    {
        $this->write('a.txt', 'a');

        try {
            TarWriter::create($this->base.'/x.tar.gz', $this->base.'/tree', ['../a.txt']);
            $this->fail('packed a path outside the release');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('../a.txt', $e->getMessage());
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }
        symlink($this->base.'/tree/a.txt', $this->base.'/tree/link.txt');
        try {
            TarWriter::create($this->base.'/x.tar.gz', $this->base.'/tree', ['a.txt', 'link.txt']);
            $this->fail('packed a symlink');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('link.txt is a symlink', $e->getMessage());
        }
    }

    public function test_hostile_entries_are_refused_by_name_and_type(): void
    {
        foreach ([
            [[['/etc/passwd', '0', 'x']], 'refusing entry name "/etc/passwd"'],
            [[['../../escape', '0', 'x']], 'refusing entry name "../../escape"'],
            [[['a/../b', '0', 'x']], 'refusing entry name'],
            [[['link', '2', '', '/etc/passwd']], 'refusing a symlink entry "link"'],
            [[['hard', '1', '', 'a.txt']], 'refusing a hard link entry "hard"'],
            [[['dev', '3', '']], 'refusing a device entry "dev"'],
            [[['pax', 'x', 'path=../x']], 'refusing a pax header entry "pax"'],
            [[['a.txt', '0', 'x'], ['a.txt', '0', 'y']], 'lists a.txt twice'],
        ] as [$entries, $message]) {
            file_put_contents($this->base.'/hostile.tar.gz', $this->tar($entries));
            $this->removeTree($this->base.'/out');
            mkdir($this->base.'/out');

            try {
                TarReader::extract($this->base.'/hostile.tar.gz', $this->base.'/out');
                $this->fail("extracted an archive that should say: {$message}");
            } catch (ArtifactRefused $e) {
                $this->assertSame('archive', $e->stage);
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_budgets_and_the_end_of_archive_are_enforced(): void
    {
        file_put_contents($this->base.'/big.tar.gz', $this->tar([['a.txt', '0', str_repeat('x', 1000)], ['b.txt', '0', str_repeat('y', 1000)]]));

        try {
            TarReader::extract($this->base.'/big.tar.gz', $this->base.'/out', ['max_unpacked_bytes' => 1500]);
            $this->fail('extracted past the byte budget');
        } catch (ArtifactRefused $e) {
            $this->assertStringContainsString('unpacked size exceeds 1500 bytes', $e->getMessage());
        }

        $this->removeTree($this->base.'/out');
        mkdir($this->base.'/out');
        try {
            TarReader::extract($this->base.'/big.tar.gz', $this->base.'/out', ['max_entries' => 1]);
            $this->fail('extracted past the entry cap');
        } catch (ArtifactRefused $e) {
            $this->assertStringContainsString('more than 1 entries', $e->getMessage());
        }

        file_put_contents($this->base.'/trailing.tar.gz', $this->tar([['a.txt', '0', 'x']], 'junk after the marker'));
        $this->removeTree($this->base.'/out');
        mkdir($this->base.'/out');
        try {
            TarReader::extract($this->base.'/trailing.tar.gz', $this->base.'/out');
            $this->fail('accepted data after the end-of-archive marker');
        } catch (ArtifactRefused $e) {
            $this->assertStringContainsString('data follows the end-of-archive marker', $e->getMessage());
        }

        file_put_contents($this->base.'/truncated.tar.gz', gzencode(substr(gzdecode($this->tar([['a.txt', '0', str_repeat('x', 600)]])), 0, 700)));
        $this->removeTree($this->base.'/out');
        mkdir($this->base.'/out');
        try {
            TarReader::extract($this->base.'/truncated.tar.gz', $this->base.'/out');
            $this->fail('accepted a truncated archive');
        } catch (ArtifactRefused $e) {
            $this->assertStringContainsString('truncated', $e->getMessage());
        }
    }

    /**
     * Where tar readers disagree with each other, refuse: what a node
     * unpacks must be what GNU tar or Python shows a reviewer.
     */
    public function test_entries_other_readers_read_differently_are_refused(): void
    {
        foreach ([
            [$this->tar([['app', '5', str_repeat("\0", 512)], ['app/a.txt', '0', 'x']]), 'which claims 512 bytes of data'],
            [$this->tar([['app/x/', '0', 'data']]), 'which is named like a directory'],
            [$this->tar([['a.txt', '0', 'x']], '', "ustar  \0"), 'not a POSIX ustar header'],
        ] as [$archive, $reason]) {
            file_put_contents($this->base.'/odd.tar.gz', $archive);
            $this->removeTree($this->base.'/out');
            mkdir($this->base.'/out');

            try {
                TarReader::extract($this->base.'/odd.tar.gz', $this->base.'/out');
                $this->fail("extracted an archive that should be refused: {$reason}");
            } catch (ArtifactRefused $e) {
                $this->assertStringContainsString($reason, $e->getMessage());
            }
        }
    }

    public function test_only_zero_padding_up_to_a_record_may_follow_the_end_marker(): void
    {
        file_put_contents($this->base.'/padded.tar.gz', $this->tar([['a.txt', '0', 'x']], str_repeat("\0", 9216)));
        $this->assertSame(['a.txt'], array_keys(TarReader::extract($this->base.'/padded.tar.gz', $this->base.'/out')));

        // More than a record of zeros is not inflated to the end to look for data.
        file_put_contents($this->base.'/bomb.tar.gz', $this->tar([['a.txt', '0', 'x']], str_repeat("\0", 4 * 1048576)));
        $this->removeTree($this->base.'/out');
        mkdir($this->base.'/out');
        try {
            TarReader::extract($this->base.'/bomb.tar.gz', $this->base.'/out');
            $this->fail('read a megabytes-long tail');
        } catch (ArtifactRefused $e) {
            $this->assertStringContainsString('data follows the end-of-archive marker', $e->getMessage());
        }
    }

    public function test_every_decompressed_byte_counts_against_one_cap(): void
    {
        $this->assertSame(1000 + 2 * TarReader::ENTRY_OVERHEAD + TarReader::TAIL, TarReader::readCap(1000, 2));
        $this->assertSame(PHP_INT_MAX, TarReader::readCap(PHP_INT_MAX, 10));

        file_put_contents($this->base.'/some.gz', gzencode(str_repeat('z', 4096)));
        $gz = gzopen($this->base.'/some.gz', 'rb');
        try {
            (new \Slipstream\Release\Core\TarStream($gz, 1000))->read(4096);
            $this->fail('read past the cap');
        } catch (ArtifactRefused $e) {
            $this->assertStringContainsString('inflates past 1000 bytes', $e->getMessage());
        } finally {
            gzclose($gz);
        }
    }

    /**
     * A write that does not complete — a full disk, a quota, a file-size
     * limit — must stop the extract, not leave a truncated file the hash of
     * the stream vouches for.
     */
    public function test_a_write_that_does_not_complete_is_refused(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || (new Process(['sh', '-c', 'exit 0']))->run() !== 0) {
            $this->markTestSkipped('needs a POSIX shell for ulimit.');
        }

        $big = '';
        for ($i = 0; $i < 4096; $i++) {
            $big .= hash('sha256', (string) $i)."\n";
        }
        $this->write('app/Big.php', $big);
        TarWriter::create($this->base.'/release.tar.gz', $this->base.'/tree', ['app/Big.php']);

        $child = new Process(['sh', '-c', 'ulimit -f 64; trap "" XFSZ; exec "$PHP" -r "$CODE"'], null, [
            'PHP' => PHP_BINARY,
            'CODE' => 'require getenv("AUTOLOAD"); try { Slipstream\Release\Core\TarReader::extract(getenv("TARGZ"), getenv("OUT"), [], json_decode(getenv("EXPECTED"), true)); echo "accepted"; } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(); }',
            'AUTOLOAD' => dirname(__DIR__, 2).'/vendor/autoload.php',
            'TARGZ' => $this->base.'/release.tar.gz',
            'OUT' => $this->base.'/out',
            'EXPECTED' => json_encode($this->expected(['app/Big.php'])),
        ]);
        $child->run();

        $this->assertStringNotContainsString('accepted', $child->getOutput());
        $this->assertStringContainsString('RuntimeException', $child->getOutput());
        $this->assertMatchesRegularExpression('/disk may be full|did not complete/', $child->getOutput());
    }

    public function test_the_manifest_is_enforced_while_extracting(): void
    {
        $this->write('a.txt', 'alpha');
        $this->write('b.txt', 'beta');
        TarWriter::create($this->base.'/release.tar.gz', $this->base.'/tree', ['a.txt', 'b.txt']);

        $expected = $this->expected(['a.txt', 'b.txt']);
        $cases = [
            'does not match the manifest' => ['a.txt' => [str_repeat('0', 64), 5]] + $expected,
            'is 5 bytes; the manifest says 6' => ['a.txt' => [hash('sha256', 'alpha'), 6]] + $expected,
            'a file the manifest does not list: b.txt' => ['a.txt' => $expected['a.txt']],
            'are not in the archive: c.txt' => $expected + ['c.txt' => [hash('sha256', 'c'), 1]],
        ];

        foreach ($cases as $message => $manifest) {
            $this->removeTree($this->base.'/out');
            mkdir($this->base.'/out');

            try {
                TarReader::extract($this->base.'/release.tar.gz', $this->base.'/out', [], $manifest);
                $this->fail("extracted an archive that should say: {$message}");
            } catch (ArtifactRefused $e) {
                $this->assertSame('files', $e->stage);
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_an_entry_cannot_write_through_a_symlinked_directory(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('needs symlinks.');
        }

        mkdir($this->base.'/elsewhere');
        symlink($this->base.'/elsewhere', $this->base.'/out/app');
        file_put_contents($this->base.'/through.tar.gz', $this->tar([['app/evil.php', '0', 'x']]));

        try {
            TarReader::extract($this->base.'/through.tar.gz', $this->base.'/out');
            $this->fail('wrote through a symlink');
        } catch (ArtifactRefused $e) {
            $this->assertStringContainsString('refusing to write through the symlink app', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->base.'/elsewhere/evil.php');
    }

    public function test_gnu_tar_reads_what_we_write(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || (new Process(['tar', '--version']))->run() !== 0) {
            $this->markTestSkipped('needs GNU tar.');
        }

        $long = str_repeat('directory-name/', 20).'file.txt';
        $this->write('app/Hello.php', 'hi');
        $this->write($long, 'deep');
        TarWriter::create($this->base.'/release.tar.gz', $this->base.'/tree', ['app/Hello.php', $long]);

        (new Process(['tar', '-xzf', $this->base.'/release.tar.gz', '-C', $this->base.'/out']))->mustRun();

        $this->assertSame('hi', file_get_contents($this->base.'/out/app/Hello.php'));
        $this->assertSame('deep', file_get_contents($this->base.'/out/'.$long));
    }

    protected function write(string $path, string $contents): void
    {
        $file = $this->base.'/tree/'.$path;
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $contents);
    }

    /**
     * @param  list<string>  $files
     * @return array<string, array{0: string, 1: int}>
     */
    protected function expected(array $files): array
    {
        $map = [];
        foreach ($files as $file) {
            $map[$file] = [hash_file('sha256', $this->base.'/tree/'.$file), (int) filesize($this->base.'/tree/'.$file)];
        }

        return $map;
    }

    /**
     * A gzipped ustar archive of arbitrary entries: [name, typeflag, data, linkname?].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3?: string}>  $entries
     */
    protected function tar(array $entries, string $trailing = '', string $magic = "ustar\x0000"): string
    {
        $tar = '';
        foreach ($entries as $entry) {
            [$name, $type, $data] = $entry;
            $header = str_pad(substr($name, 0, 100), 100, "\0")
                .sprintf("%07o\0", 0644).sprintf("%07o\0", 0).sprintf("%07o\0", 0)
                .sprintf("%011o\0", strlen($data)).sprintf("%011o\0", 0)
                .'        '.$type
                .str_pad(substr($entry[3] ?? '', 0, 100), 100, "\0")
                .$magic.str_repeat("\0", 32).str_repeat("\0", 32)
                .sprintf("%07o\0", 0).sprintf("%07o\0", 0)
                .str_repeat("\0", 155).str_repeat("\0", 12);
            $sum = 0;
            for ($i = 0; $i < 512; $i++) {
                $sum += ord($header[$i]);
            }
            $header = substr_replace($header, sprintf("%06o\0 ", $sum), 148, 8);
            $tar .= $header.$data.str_repeat("\0", (512 - strlen($data) % 512) % 512);
        }

        return gzencode($tar.str_repeat("\0", 1024).$trailing);
    }
}
