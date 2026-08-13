<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Diagnostics\FpmPool;
use Slipstream\Tests\TestCase;

class FpmPoolTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'slipstream-fpm-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    protected function pool(string $name, string $contents): void
    {
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.$name, $contents);
    }

    protected function globs(): array
    {
        return [$this->dir.DIRECTORY_SEPARATOR.'*.conf'];
    }

    public function test_it_reads_the_pool_user(): void
    {
        $this->pool('www.conf', "[www]\nuser = deploy\ngroup = deploy\n");

        $this->assertSame('deploy', FpmPool::detectUser($this->globs()));
    }

    public function test_it_ignores_commented_out_users(): void
    {
        $this->pool('www.conf', "[www]\n; user = www-data\n#user = nobody\nuser = deploy\n");

        $this->assertSame('deploy', FpmPool::detectUser($this->globs()));
    }

    public function test_it_tolerates_whitespace_variations(): void
    {
        $this->pool('www.conf', "[www]\n   user=www-data\n");

        $this->assertSame('www-data', FpmPool::detectUser($this->globs()));
    }

    public function test_it_returns_null_when_no_pool_declares_a_user(): void
    {
        $this->pool('www.conf', "[www]\nlisten = /run/php/php8.4-fpm.sock\n");

        $this->assertNull(FpmPool::detectUser($this->globs()));
    }

    public function test_it_returns_null_when_there_are_no_pool_configs(): void
    {
        $this->assertNull(FpmPool::detectUser($this->globs()));
    }

    public function test_owner_of_a_missing_path_is_null(): void
    {
        $this->assertNull(FpmPool::ownerOf($this->dir.DIRECTORY_SEPARATOR.'nope'));
    }
}
