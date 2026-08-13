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
        $this->removeTree($this->dir);

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

    /**
     * Two pools, two users: glob() sort order must not pick the winner —
     * callers print chown commands from this answer, and a confident guess at
     * the wrong pool user is a paste-ready outage.
     */
    public function test_two_pools_with_different_users_is_ambiguous(): void
    {
        $this->pool('app.conf', "[app]\nuser = deploy\n");
        $this->pool('www.conf', "[www]\nuser = www-data\n");

        $this->assertNull(FpmPool::detectUser($this->globs()));
        $this->assertSame(['deploy', 'www-data'], FpmPool::detectUsers($this->globs()));
    }

    public function test_two_pools_with_the_same_user_is_not_ambiguous(): void
    {
        $this->pool('app.conf', "[app]\nuser = www-data\n");
        $this->pool('www.conf', "[www]\nuser = www-data\n");

        $this->assertSame('www-data', FpmPool::detectUser($this->globs()));
    }

    /**
     * Tiers resolve independently: pools of the configured PHP version answer
     * before another version's pools ever get a vote.
     */
    public function test_an_earlier_glob_tier_wins_over_a_later_one(): void
    {
        $configured = $this->dir.DIRECTORY_SEPARATOR.'v84';
        $other = $this->dir.DIRECTORY_SEPARATOR.'v81';
        mkdir($configured, 0777, true);
        mkdir($other, 0777, true);
        file_put_contents($configured.DIRECTORY_SEPARATOR.'www.conf', "[www]\nuser = deploy\n");
        file_put_contents($other.DIRECTORY_SEPARATOR.'www.conf', "[www]\nuser = www-data\n");

        $this->assertSame('deploy', FpmPool::detectUser([
            $configured.DIRECTORY_SEPARATOR.'*.conf',
            $other.DIRECTORY_SEPARATOR.'*.conf',
        ]));
    }

    public function test_it_returns_null_when_there_are_no_pool_configs(): void
    {
        $this->assertNull(FpmPool::detectUser($this->globs()));
    }

    public function test_the_configured_php_version_is_looked_at_first(): void
    {
        config()->set('slipstream.services.fpm', 'php8.4-fpm');

        $globs = FpmPool::globsForConfiguredService();

        // A box with 8.1 and 8.4 installed must not answer with whichever pool
        // directory sorts first.
        $this->assertStringContainsString('/etc/php/8.4/fpm/pool.d/*.conf', $globs[0]);
        $this->assertContains('/etc/php/*/fpm/pool.d/*.conf', $globs, 'the generic glob stays as a fallback');
    }

    public function test_an_unversioned_service_name_falls_back_to_the_generic_globs(): void
    {
        config()->set('slipstream.services.fpm', 'php-fpm');

        $this->assertSame(FpmPool::DEFAULT_GLOBS, FpmPool::globsForConfiguredService());
    }

    public function test_owner_of_a_missing_path_is_null(): void
    {
        $this->assertNull(FpmPool::ownerOf($this->dir.DIRECTORY_SEPARATOR.'nope'));
    }
}
