<?php

namespace Slipstream\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * stubs/nginx-slipstream.conf hands only the front controller to PHP-FPM:
 * a .php file that lands under public/ (an upload in storage/app/public,
 * reachable as /storage/x.php) must never execute.
 */
class NginxStubTest extends TestCase
{
    public function test_only_index_php_reaches_php_fpm(): void
    {
        $stub = (string) file_get_contents(dirname(__DIR__, 2).'/stubs/nginx-slipstream.conf');
        $active = implode("\n", array_filter(explode("\n", $stub), fn (string $line) => ! str_starts_with(ltrim($line), '#')));

        $this->assertMatchesRegularExpression('#location ~ \^/index\\\\\.php\(/\|\$\) \{[^}]*fastcgi_pass#s', $active);
        $this->assertMatchesRegularExpression('#location ~ \\\\\.php\$ \{\s*return 404;\s*\}#', $active);
        $this->assertSame(1, substr_count($active, 'fastcgi_pass'), 'no other location passes to PHP-FPM');
    }
}
