<?php

namespace Slipstream\Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Slipstream\Release\ArtifactSource;
use Slipstream\Release\Core\Baseline;
use Slipstream\Release\Core\SodiumSigner;
use Slipstream\Release\DeployRef;
use Slipstream\Tests\Support\ArtifactFixture;
use Slipstream\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * stubs/slipstream-release.php: the generated single-file tool must be the
 * Core classes as committed, and must verify, refuse, unpack, publish, roll
 * back and check a live tree exactly as the node does.
 */
class ReleaseStubTest extends TestCase
{
    protected ArtifactFixture $fixture;

    protected string $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = dirname(__DIR__, 2).'/stubs/slipstream-release.php';
        $this->fixture = (new ArtifactFixture)->configure()->writeCheckout()->package();
        $this->fixture->publish();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();

        parent::tearDown();
    }

    public function test_the_committed_tool_is_what_the_build_produces(): void
    {
        require_once dirname(__DIR__, 2).'/stubs/src/build-release-tool.php';

        $this->assertSame(
            str_replace("\r\n", "\n", (string) file_get_contents($this->tool)),
            slipstream_release_tool_source(),
            'stubs/slipstream-release.php is out of date: run composer stub:build'
        );
    }

    public function test_it_verifies_a_pointer_and_refuses_a_tampered_one(): void
    {
        $pointer = $this->fixture->root.'/pointer.json';
        copy($this->fixture->store.'/app/refs/heads/production.json', $pointer);

        $process = $this->tool('verify-pointer', ['--ref' => 'refs/heads/production', '--environment' => 'production', '--keys' => $this->keys(), '--pointer' => $pointer]);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('sha='.$this->fixture->sha."\n", $process->getOutput());
        $this->assertStringContainsString('sequence='.$this->fixture->pointer->sequence."\n", $process->getOutput());
        $this->assertStringContainsString("previous_manifest_sha256=\n", $process->getOutput());

        $process = $this->tool('verify-pointer', ['--ref' => 'refs/heads/staging', '--environment' => 'production', '--keys' => $this->keys(), '--pointer' => $pointer]);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('refused (pointer): it names refs/heads/production, not refs/heads/staging', $process->getErrorOutput());

        $this->fixture->resignPointer($this->fixture->otherSigner());
        copy($this->fixture->store.'/app/refs/heads/production.json', $pointer);
        $process = $this->tool('verify-pointer', ['--ref' => 'refs/heads/production', '--environment' => 'production', '--keys' => $this->keys(), '--pointer' => $pointer]);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('signature does not verify', $process->getErrorOutput());
    }

    public function test_verify_pointer_prints_the_previous_release_and_its_manifest(): void
    {
        $this->fixture->publishSecond();
        $pointer = $this->fixture->root.'/pointer.json';
        copy($this->fixture->store.'/app/refs/heads/production.json', $pointer);

        $process = $this->tool('verify-pointer', ['--ref' => 'refs/heads/production', '--environment' => 'production', '--keys' => $this->keys(), '--pointer' => $pointer]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('previous_sha='.$this->fixture->firstSha."\n", $process->getOutput());
        $this->assertStringContainsString('previous_manifest_sha256='.$this->fixture->pointer->previous['manifest_sha256']."\n", $process->getOutput());
    }

    public function test_it_verifies_and_unpacks_a_release_and_refuses_a_tampered_archive(): void
    {
        $into = $this->fixture->root.'/unpacked';
        $arguments = $this->releaseArguments($into);

        $process = $this->tool('verify-release', $arguments);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('files=', $process->getOutput());
        $this->assertSame($this->fixture->sha, trim((string) file_get_contents($into.'/RELEASE_SHA')));
        $this->assertFileExists($into.'/.slipstream/manifest.sig');
        $this->assertFileExists($into.'/vendor/slipstream/slipstream/composer.json');

        $this->fixture->tamperArchive();
        $process = $this->tool('verify-release', ['--extract-to' => $this->fixture->root.'/unpacked-2'] + $arguments);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('refused (archive): the archive does not match the signed manifest', $process->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->fixture->root.'/unpacked-2/app');
    }

    public function test_verify_release_refuses_another_ref_and_leaves_nothing_behind(): void
    {
        $process = $this->tool('verify-release', ['--ref' => 'refs/heads/staging'] + $this->releaseArguments($this->fixture->root.'/staging'));
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('it was built for refs/heads/production; this node deploys refs/heads/staging', $process->getErrorOutput());

        // A file whose hash is wrong surfaces part-way through the unpack.
        $this->fixture->publishWithFileHashMismatch();
        $into = $this->fixture->root.'/partial';
        $process = $this->tool('verify-release', $this->releaseArguments($into));
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('does not match the manifest', $process->getErrorOutput());
        $this->assertDirectoryDoesNotExist($into, 'what was unpacked before the refusal is removed');
    }

    public function test_it_publishes_and_rolls_back_through_a_file_store_as_the_node_reads_it(): void
    {
        $store = $this->fixture->root.'/ci-store';
        mkdir($store);
        [$secret, $signer, $common] = $this->ciKey($store);

        $process = $this->tool('publish', $common + $this->built());
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('refs/heads/production → aaaaaaa (sequence', $process->getOutput());
        $this->assertFileExists($store.'/releases/'.$this->fixture->sha.'/release.tar.gz');
        $this->assertFileExists($store.'/refs/heads/production.json');

        // The node side reads what the tool wrote.
        config()->set('filesystems.disks.ci', ['driver' => 'local', 'root' => $store]);
        config()->set('slipstream.artifacts.disk', 'ci');
        config()->set('slipstream.artifacts.prefix', '');
        config()->set('slipstream.artifacts.public_keys', 'ci-key:'.base64_encode($signer->publicKey()));
        $pointer = app(ArtifactSource::class)->pointer(DeployRef::parse('refs/heads/production'));
        $this->assertSame([$this->fixture->sha, 'actions', 'ci-key', null], [$pointer->sha, $pointer->by, $pointer->keyId, $pointer->previous]);
        $this->assertTrue(Storage::disk('ci')->exists('releases/'.$this->fixture->sha.'/manifest.sig'));

        // A second release, then a rollback to the first.
        $first = $this->fixture->sha;
        $this->fixture->writeCheckout(['app/Hello.php' => '<?php // v2'])->package(str_repeat('b', 40));
        $process = $this->tool('publish', ['--sha' => str_repeat('b', 40)] + $common + $this->built());
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $second = app(ArtifactSource::class)->pointer(DeployRef::parse('refs/heads/production'), fresh: true);
        $this->assertSame([str_repeat('b', 40), $first], [$second->sha, $second->previous['sha']]);

        $process = $this->tool('rollback', ['--sha' => $first] + $common);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $back = app(ArtifactSource::class)->pointer(DeployRef::parse('refs/heads/production'), fresh: true);
        $this->assertSame([$first, null], [$back->sha, $back->previous]);
        $this->assertGreaterThan($second->sequence, $back->sequence);
    }

    public function test_publish_needs_the_commit_and_the_nodes_keys_from_the_publish_job(): void
    {
        $store = $this->fixture->root.'/ci-store';
        mkdir($store);
        [, , $common] = $this->ciKey($store);

        $process = $this->tool('publish', array_diff_key($common, ['--sha' => true]) + $this->built(), ['GITHUB_SHA' => '']);
        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('--sha is required', $process->getErrorOutput());

        $process = $this->tool('publish', ['--sha' => str_repeat('c', 40)] + $common + $this->built());
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('the build says it is '.$this->fixture->sha, $process->getErrorOutput());

        $process = $this->tool('publish', array_diff_key($common, ['--keys' => true]) + $this->built());
        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('--keys is required', $process->getErrorOutput());

        // Signed with a key the nodes do not hold: refused before anything uploads.
        $process = $this->tool('publish', ['--keys' => $this->keys()] + $common + $this->built());
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Nodes would refuse the statement', $process->getErrorOutput());
        $this->assertSame([], glob($store.'/*') ?: []);
    }

    public function test_a_published_release_is_never_replaced(): void
    {
        $store = $this->fixture->root.'/ci-store';
        mkdir($store);
        [, , $common] = $this->ciKey($store);

        $this->assertSame(0, $this->tool('publish', $common + $this->built())->getExitCode());
        $again = $this->tool('publish', $common + $this->built());
        $this->assertSame(0, $again->getExitCode(), $again->getErrorOutput());
        $this->assertStringContainsString('Already in the store: release.tar.gz', $again->getOutput());

        $archive = hash_file('sha256', $store.'/releases/'.$this->fixture->sha.'/release.tar.gz');
        $this->fixture->writeCheckout(['app/Hello.php' => '<?php // rebuilt'])->package();
        $process = $this->tool('publish', $common + $this->built());
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('already in the store with different content', $process->getErrorOutput());
        $this->assertSame($archive, hash_file('sha256', $store.'/releases/'.$this->fixture->sha.'/release.tar.gz'));
    }

    public function test_verify_tree_checks_the_live_release_from_outside_it(): void
    {
        $release = $this->fixture->unpackInto($this->fixture->appRoot.'/releases/one');
        $tree = fn (array $extra = []) => $this->tool('verify-tree', $extra + ['--release' => $release, '--keys' => $this->keys(), '--environment' => 'production']);

        $process = $tree();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('ok: ', $process->getOutput());

        // The verifier the release carries is just another file to it.
        file_put_contents($release.'/vendor/slipstream/slipstream/composer.json', '{"name":"slipstream/slipstream","patched":true}');
        $process = $tree(['--json' => true]);
        $this->assertSame(1, $process->getExitCode());
        $line = json_decode(trim($process->getOutput()), true);
        $this->assertSame('artifact.verify_drift', $line['event']);
        $this->assertSame(['vendor/slipstream/slipstream/composer.json'], $line['modified']);

        unlink($release.'/.slipstream/manifest.json');
        $process = $tree();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('no signed manifest', $process->getOutput());
    }

    public function test_verify_tree_counts_a_missing_cache_record_and_a_planted_hot_file_as_drift(): void
    {
        $release = $this->fixture->unpackInto($this->fixture->appRoot.'/releases/four');
        $tree = fn () => $this->tool('verify-tree', ['--release' => $release, '--keys' => $this->keys(), '--environment' => 'production']);

        unlink($release.'/.slipstream/generated.json');
        $process = $tree();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('generated.json is missing', $process->getOutput());

        file_put_contents($release.'/.slipstream/generated.json', Baseline::toJson(Baseline::record($release)));
        file_put_contents($release.'/public/hot', 'https://attacker.example');
        $process = $tree();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('public/hot', $process->getOutput());
    }

    public function test_verify_tree_pins_the_recorded_cache_hashes_in_a_state_directory(): void
    {
        $release = $this->fixture->unpackInto($this->fixture->appRoot.'/releases/two');
        @mkdir($release.'/bootstrap/cache', 0777, true);
        file_put_contents($release.'/bootstrap/cache/config.php', '<?php return [];');
        file_put_contents($release.'/.slipstream/generated.json', Baseline::toJson(Baseline::record($release)));
        $state = $this->fixture->root.'/state';
        $tree = fn () => $this->tool('verify-tree', ['--release' => $release, '--keys' => $this->keys(), '--environment' => 'production', '--state-dir' => $state]);

        $this->assertSame(0, $tree()->getExitCode());
        $this->assertCount(1, glob($state.'/*.json') ?: []);

        // Someone with write access to the tree changes a cache and the
        // record of it alike: the pinned copy still knows.
        file_put_contents($release.'/bootstrap/cache/config.php', '<?php system($_GET["c"]); return [];');
        file_put_contents($release.'/.slipstream/generated.json', Baseline::toJson(Baseline::record($release)));
        $process = $tree();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('bootstrap/cache/config.php', $process->getOutput());
        $this->assertStringContainsString('the recorded hashes changed since the release was first checked', $process->getOutput());
    }

    public function test_verify_tree_accepts_links_only_at_the_shared_paths_it_is_told(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('needs symlinks.');
        }

        $release = $this->fixture->unpackInto($this->fixture->appRoot.'/releases/three');
        mkdir($this->fixture->appRoot.'/shared/uploads', 0777, true);
        symlink($this->fixture->appRoot.'/shared/uploads', $release.'/public/uploads');
        $arguments = ['--release' => $release, '--keys' => $this->keys(), '--environment' => 'production'];

        $process = $this->tool('verify-tree', $arguments);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('symlinks (1): public/uploads', $process->getOutput());

        $arguments += ['--shared' => 'public/uploads'];
        $this->assertSame(0, $this->tool('verify-tree', $arguments)->getExitCode());

        // public/storage: a link into the release's (shared) storage, as
        // storage:link makes it, and nothing else.
        mkdir($this->fixture->appRoot.'/storage/app/public', 0777, true);
        $this->removeTree($release.'/storage');
        symlink($this->fixture->appRoot.'/storage', $release.'/storage');
        symlink($release.'/storage/app/public', $release.'/public/storage');
        $this->assertSame(0, $this->tool('verify-tree', $arguments)->getExitCode());

        unlink($release.'/public/storage');
        symlink($this->fixture->appRoot.'/shared', $release.'/public/storage');
        $process = $this->tool('verify-tree', $arguments);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('symlinks (1): public/storage', $process->getOutput());
    }

    public function test_publish_writes_s3_objects_write_once_and_the_pointer_conditionally(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('the fake aws is a POSIX shell script.');
        }

        $s3 = $this->fixture->root.'/s3';
        $bin = $this->fixture->root.'/bin';
        mkdir($s3);
        mkdir($bin);
        file_put_contents($bin.'/fake-aws.php', self::FAKE_AWS);
        file_put_contents($bin.'/aws', "#!/bin/sh\nexec \"".PHP_BINARY.'" "'.$bin."/fake-aws.php\" \"\$@\"\n");
        chmod($bin.'/aws', 0755);
        $env = ['PATH' => $bin.':'.getenv('PATH'), 'FAKE_S3_ROOT' => $s3];
        [, , $common] = $this->ciKey('s3://app-releases/production');

        $process = $this->tool('publish', $common + $this->built(), $env);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $calls = (string) file_get_contents($s3.'/calls.log');
        $this->assertStringContainsString('s3api put-object --bucket app-releases --key production/releases/'.$this->fixture->sha.'/release.tar.gz --body ', $calls);
        $this->assertMatchesRegularExpression('#put-object --bucket app-releases --key production/releases/\S+/release\.tar\.gz --body \S+ --if-none-match \* #', $calls);
        $this->assertMatchesRegularExpression('#put-object --bucket app-releases --key production/refs/heads/production\.json --body \S+ --output json --if-none-match \*#', $calls);

        // A re-run: the release objects exist with the same bytes, so the
        // 412s are compared and skipped; the pointer is replaced If-Match.
        $process = $this->tool('publish', $common + $this->built(), $env);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Already in the store: release.tar.gz', $process->getOutput());
        $this->assertMatchesRegularExpression('#refs/heads/production\.json --body \S+ --output json --if-match "[0-9a-f]{32}"#', (string) file_get_contents($s3.'/calls.log'));

        // Another publish moved the pointer between the read and the write.
        touch($s3.'/force-conflict');
        $before = (string) file_get_contents($s3.'/app-releases/production/refs/heads/production.json');
        $process = $this->tool('publish', $common + $this->built(), $env);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Another publish moved refs/heads/production.json while this one ran', $process->getErrorOutput());
        $this->assertSame($before, (string) file_get_contents($s3.'/app-releases/production/refs/heads/production.json'));
    }

    public function test_verify_tree_holds_every_link_to_its_exact_target_under_the_app_root(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('needs symlinks (run under WSL).');
        }

        $root = $this->fixture->appRoot;
        $release = $this->fixture->unpackInto($root.'/releases/five'); // app root: two levels up
        mkdir($root.'/storage/app/public', 0777, true);
        mkdir($root.'/shared/uploads', 0777, true);
        $this->removeTree($release.'/storage');
        symlink($root.'/storage', $release.'/storage');
        symlink($root.'/.env', $release.'/.env');
        symlink($release.'/storage/app/public', $release.'/public/storage');
        symlink($root.'/shared/uploads', $release.'/public/uploads');
        $tree = fn (array $extra = []) => $this->tool('verify-tree', $extra + ['--release' => $release, '--keys' => $this->keys(), '--environment' => 'production', '--state-dir' => $this->fixture->root.'/state', '--shared' => 'public/uploads=shared/uploads']);

        $process = $tree();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringNotContainsString('without --state-dir', $process->getErrorOutput());

        foreach ([['.env', $root.'/storage'], ['public/uploads', $root], ['storage', $root]] as [$path, $target]) {
            $original = readlink($release.'/'.$path);
            unlink($release.'/'.$path);
            symlink($target, $release.'/'.$path);

            $process = $tree();
            $this->assertSame(1, $process->getExitCode(), "{$path} pointed at {$target}");
            $this->assertStringContainsString($path, $process->getOutput());

            unlink($release.'/'.$path);
            symlink($original, $release.'/'.$path);
        }

        // --links names more storage links; public/storage is still held.
        mkdir($root.'/storage/app/media', 0777, true);
        symlink($release.'/storage/app/media', $release.'/public/media');
        $this->assertSame(1, $tree()->getExitCode(), 'public/media is drift until it is named');
        $process = $tree(['--links' => 'public/media=storage/app/media']);
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        mkdir($root.'/storage/logs', 0777, true);
        unlink($release.'/public/storage');
        symlink($root.'/storage/logs', $release.'/public/storage');
        $process = $tree(['--links' => 'public/media=storage/app/media']);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('symlinks (1): public/storage', $process->getOutput());
        unlink($release.'/public/storage');
        symlink($release.'/storage/app/public', $release.'/public/storage');

        // An explicit --app-root that is not where the links lead: all drift.
        $process = $tree(['--app-root' => $this->fixture->root.'/other']);
        $this->assertSame(1, $process->getExitCode());
    }

    public function test_verify_tree_says_when_it_runs_without_a_state_directory(): void
    {
        $release = $this->fixture->unpackInto($this->fixture->appRoot.'/releases/six');

        $process = $this->tool('verify-tree', ['--release' => $release, '--keys' => $this->keys(), '--environment' => 'production']);

        $this->assertStringContainsString('without --state-dir the cache record inside the release is only as safe as the release', $process->getErrorOutput());
    }

    public function test_verify_tree_refuses_link_targets_it_cannot_hold_a_link_to(): void
    {
        $release = $this->fixture->unpackInto($this->fixture->appRoot.'/releases/seven');
        $arguments = ['--release' => $release, '--keys' => $this->keys(), '--environment' => 'production', '--state-dir' => $this->fixture->root.'/state'];

        // A storage link with no target would turn a correct link into
        // drift, and a target outside the app root can never be met.
        foreach ([['--links' => 'public/media'], ['--links' => 'public/media=/srv/media'], ['--shared' => 'public/uploads=../uploads'], ['--shared' => 'public/uploads=']] as $bad) {
            $process = $this->tool('verify-tree', $bad + $arguments);
            $this->assertSame(2, $process->getExitCode(), (string) json_encode($bad));
            $this->assertStringContainsString('relative to the app root', $process->getErrorOutput());
        }

        // public/storage has a known target, so its name alone still works.
        $process = $this->tool('verify-tree', ['--links' => 'public/storage'] + $arguments);
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    public function test_usage_and_missing_options_exit_2(): void
    {
        $this->assertSame(2, $this->tool('frobnicate', [])->getExitCode());
        $process = $this->tool('verify-pointer', ['--ref' => 'refs/heads/production']);
        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('--pointer is required', $process->getErrorOutput());
    }

    /**
     * A misspelt option, or one newer than the installed copy, would be
     * ignored and quietly weaken the check it was meant to set.
     */
    public function test_an_option_the_command_does_not_take_exits_2(): void
    {
        $release = $this->fixture->unpackInto($this->fixture->appRoot.'/releases/nine');
        $arguments = ['--release' => $release, '--keys' => $this->keys(), '--environment' => 'production'];

        $process = $this->tool('verify-tree', $arguments + ['--statedir' => $this->fixture->root.'/state']);
        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('verify-tree takes no --statedir', $process->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->fixture->root.'/state');

        $process = $this->tool('publish', $this->built() + ['--sha' => $this->fixture->sha, '--app-root' => '/srv']);
        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('publish takes no --app-root', $process->getErrorOutput());

        $this->assertSame(0, $this->tool('verify-tree', $arguments + ['--json' => true])->getExitCode(), 'its own options pass');
    }

    /**
     * The app root comes from the path the timer names, never through a
     * link the deploy user can re-aim, and the release must be one of its
     * releases.
     */
    public function test_verify_tree_takes_the_app_root_only_from_the_path_it_is_given(): void
    {
        $outside = $this->fixture->unpackInto($this->fixture->root.'/copy');
        $arguments = ['--keys' => $this->keys(), '--environment' => 'production'];

        $process = $this->tool('verify-tree', $arguments + ['--release' => $outside]);
        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('cannot tell the app root', $process->getErrorOutput());

        $process = $this->tool('verify-tree', $arguments + ['--release' => $outside, '--app-root' => $this->fixture->appRoot, '--json' => true]);
        $this->assertSame(1, $process->getExitCode());
        $line = json_decode(trim($process->getOutput()), true);
        $this->assertSame('the release is not one of '.str_replace('\\', '/', $this->fixture->appRoot).'/releases/', $line['reason']);
    }

    public function test_verify_tree_holds_a_current_aimed_outside_the_app_roots_releases_as_drift(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('needs symlinks (run under WSL).');
        }

        $root = $this->fixture->appRoot;
        $release = $this->fixture->unpackInto($root.'/releases/ten');
        symlink($release, $root.'/current');
        $arguments = ['--release' => $root.'/current', '--keys' => $this->keys(), '--environment' => 'production'];
        $this->assertSame(0, $this->tool('verify-tree', $arguments)->getExitCode(), 'the app root is current\'s own directory');

        // current re-aimed at a copy elsewhere, even one under a releases/
        // directory of its own: the app root stays where current is.
        $copy = $this->fixture->unpackInto($this->fixture->root.'/x/releases/ten');
        unlink($root.'/current');
        symlink($copy, $root.'/current');
        $process = $this->tool('verify-tree', $arguments);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('the release is not one of '.$root.'/releases/', $process->getOutput());
    }

    /**
     * A pin is keyed by where the release is, so moving one makes the next
     * run a first look. Only a deploy should cause one, and every line says
     * whether it was.
     */
    public function test_verify_tree_says_when_a_run_is_its_first_look_at_a_release(): void
    {
        $releases = $this->fixture->appRoot.'/releases';
        $release = $this->fixture->unpackInto($releases.'/eleven');
        $tree = fn (string $at, array $extra = []) => json_decode(trim($this->tool('verify-tree', $extra + ['--release' => $at, '--keys' => $this->keys(), '--environment' => 'production', '--json' => true])->getOutput()), true);
        $state = ['--state-dir' => $this->fixture->root.'/state'];

        $this->assertNull($tree($release)['first_look'], 'nothing is pinned without a state directory');
        $this->assertTrue($tree($release, $state)['first_look']);
        $this->assertFalse($tree($release, $state)['first_look']);

        // The deploy user moves the release; the next run pins it afresh.
        rename($release, $releases.'/twelve');
        $line = $tree($releases.'/twelve', $state);
        $this->assertTrue($line['first_look']);
        $this->assertSame('artifact.verify_ok', $line['event']);
    }

    public function test_verify_tree_checks_the_compiled_views_the_deploy_recorded(): void
    {
        $release = $this->fixture->unpackInto($this->fixture->appRoot.'/releases/thirteen');
        @mkdir($release.'/bootstrap/cache/views', 0777, true);
        file_put_contents($release.'/bootstrap/cache/views/0a1b.php', '<form><?php echo e($title); ?></form>');
        file_put_contents($release.'/.slipstream/generated.json', Baseline::toJson(Baseline::record($release)));
        $tree = fn () => $this->tool('verify-tree', ['--release' => $release, '--keys' => $this->keys(), '--environment' => 'production', '--state-dir' => $this->fixture->root.'/state']);
        $this->assertSame(0, $tree()->getExitCode());

        file_put_contents($release.'/bootstrap/cache/views/0a1b.php', '<form><?php echo e($title); ?></form><script src="https://skim.example/x.js"></script>');
        file_put_contents($release.'/bootstrap/cache/views/ffff.php', '<?php system($_GET["c"]);');
        $process = $tree();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('bootstrap/cache/views/0a1b.php, bootstrap/cache/views/ffff.php', $process->getOutput());
    }

    /**
     * Records and pins from before 0.6.4 covered the top of bootstrap/cache
     * only: the first release a 0.6.3 deploy puts in place compiles its
     * views where neither looked.
     */
    public function test_verify_tree_compares_an_older_record_or_pin_over_what_it_covered(): void
    {
        $release = $this->fixture->unpackInto($this->fixture->appRoot.'/releases/fourteen');
        @mkdir($release.'/bootstrap/cache/views', 0777, true);
        file_put_contents($release.'/bootstrap/cache/config.php', '<?php return [];');
        file_put_contents($release.'/.slipstream/generated.json', (string) json_encode(['format' => 1, 'files' => Baseline::record($release, 1)]));
        $state = $this->fixture->root.'/state';
        mkdir($state);
        // The pin 0.6.3 wrote on its first look: no format in it.
        file_put_contents($state.'/'.sha1(str_replace('\\', '/', (string) realpath($release))).'.json', (string) json_encode([
            'release' => str_replace('\\', '/', (string) realpath($release)), 'manifest_sha256' => hash_file('sha256', $release.'/.slipstream/manifest.json'),
            'generated' => Baseline::record($release, 1), 'pinned_at' => '2026-10-20T00:00:00+00:00',
        ]));
        file_put_contents($release.'/bootstrap/cache/views/0a1b.php', '<form></form>');
        $tree = fn () => $this->tool('verify-tree', ['--release' => $release, '--keys' => $this->keys(), '--environment' => 'production', '--state-dir' => $state, '--json' => true]);

        $line = json_decode(trim($tree()->getOutput()), true);
        $this->assertSame('artifact.verify_ok', $line['event'], (string) json_encode($line));
        $this->assertFalse($line['first_look']);

        file_put_contents($release.'/bootstrap/cache/config.php', '<?php system($_GET["c"]); return [];');
        $this->assertSame(['bootstrap/cache/config.php'], json_decode(trim($tree()->getOutput()), true)['generated']);
    }

    /**
     * An `aws` that keeps objects in a directory and honours the two
     * conditional headers the tool sends; force-conflict makes the next
     * pointer write lose a race.
     */
    protected const FAKE_AWS = <<<'PHP'
<?php
$root = getenv('FAKE_S3_ROOT');
$args = array_slice($argv, 1);
file_put_contents($root.'/calls.log', implode(' ', $args)."\n", FILE_APPEND);
$opt = function (string $name) use ($args) {
    $i = array_search('--'.$name, $args, true);
    return $i === false ? null : ($args[$i + 1] ?? null);
};
$path = $root.'/'.$opt('bucket').'/'.$opt('key');
$etag = fn (string $file) => '"'.md5_file($file).'"';
$precondition = function () {
    fwrite(STDERR, "\nAn error occurred (PreconditionFailed) when calling the PutObject operation: At least one of the pre-conditions you specified did not hold\n");
    exit(254);
};
if (($args[0] ?? '') !== 's3api') {
    fwrite(STDERR, "unsupported\n");
    exit(2);
}
if ($args[1] === 'get-object') {
    if (! is_file($path)) {
        fwrite(STDERR, "\nAn error occurred (NoSuchKey) when calling the GetObject operation: The specified key does not exist.\n");
        exit(254);
    }
    copy($path, $args[6]);
    echo json_encode(['ETag' => $etag($path)]);
    exit(0);
}
if ($args[1] === 'put-object') {
    if (str_ends_with($path, '.json') && str_contains($path, '/refs/') && is_file($root.'/force-conflict')) {
        $precondition();
    }
    if ($opt('if-none-match') === '*' && is_file($path)) {
        $precondition();
    }
    if (($match = $opt('if-match')) !== null && (! is_file($path) || $etag($path) !== $match)) {
        $precondition();
    }
    @mkdir(dirname($path), 0777, true);
    copy($opt('body'), $path);
    echo json_encode(['ETag' => $etag($path)]);
    exit(0);
}
fwrite(STDERR, "unsupported\n");
exit(2);
PHP;

    protected function keys(): string
    {
        return $this->fixture->keyId.':'.$this->fixture->publicKey;
    }

    /**
     * A CI key the nodes trust, and the options every publish passes.
     *
     * @return array{0: string, 1: SodiumSigner, 2: array<string, string>}
     */
    protected function ciKey(string $store): array
    {
        $secret = 'sodium:'.base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $signer = SodiumSigner::fromSpec($secret, 'ci-key');

        return [$secret, $signer, [
            '--ref' => 'refs/heads/production', '--environment' => 'production', '--key' => $secret, '--key-id' => 'ci-key',
            '--store' => str_contains($store, '://') ? $store : 'file://'.$store, '--by' => 'actions',
            '--sha' => $this->fixture->sha, '--keys' => 'ci-key:'.base64_encode($signer->publicKey()),
        ]];
    }

    /**
     * @return array<string, string>
     */
    protected function built(): array
    {
        return ['--archive' => $this->fixture->dist.'/release.tar.gz', '--manifest' => $this->fixture->dist.'/manifest.json'];
    }

    /**
     * @return array<string, string>
     */
    protected function releaseArguments(string $into): array
    {
        $release = $this->fixture->store.'/app/releases/'.$this->fixture->sha;

        return [
            '--sha' => $this->fixture->sha, '--ref' => 'refs/heads/production', '--environment' => 'production', '--keys' => $this->keys(),
            '--manifest' => $release.'/manifest.json', '--signature' => $release.'/manifest.sig',
            '--archive' => $release.'/release.tar.gz', '--extract-to' => $into,
            '--manifest-sha256' => $this->fixture->pointer->manifestSha256,
        ];
    }

    /**
     * @param  array<string, string|bool>  $options
     * @param  array<string, string>  $env
     */
    protected function tool(string $command, array $options, array $env = []): Process
    {
        $argv = [PHP_BINARY, $this->tool, $command];
        foreach ($options as $name => $value) {
            $argv[] = $value === true ? $name : $name.'='.$value;
        }

        $process = new Process($argv, null, $env + ['GITHUB_SHA' => false]);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }
}
