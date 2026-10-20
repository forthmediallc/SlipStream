<?php

namespace Slipstream\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slipstream\Release\Core\ReleaseRules;

/**
 * What a release must never carry, by name at any depth or by content.
 */
class ReleaseRulesTest extends TestCase
{
    public function test_never_shipped_names_match_at_any_depth(): void
    {
        foreach ([
            '.git', 'vendor/acme/pkg/.git/config', 'node_modules/x.js', 'packages/ui/node_modules/y/index.js',
            '.env', 'app/Sub/.env', '.env.production', '.env~', '.envrc', '.env.d/extra',
            'auth.json', 'config/auth.json', '.npmrc', 'packages/ui/.npmrc', '.yarnrc', '.yarnrc.yml', '.sentryclirc',
            'database/database.sqlite', 'storage/app/data.sqlite3',
        ] as $path) {
            $this->assertTrue(ReleaseRules::neverShips($path), $path);
        }

        foreach ([
            '.env.example', 'app/Env.php', 'config/environment.php', '.gitignore', 'storage/logs/.gitignore',
            'vendor/acme/pkg/data.sqlite', 'app/Http/Auth/Login.php', 'composer.json',
        ] as $path) {
            $this->assertFalse(ReleaseRules::neverShips($path), $path);
        }
    }

    public function test_names_match_whatever_their_case_and_credential_files_are_covered(): void
    {
        foreach (['.ENV', 'config/.Env.production', 'AUTH.JSON', '.NPMRC', 'NODE_MODULES/x.js', '.netrc', '_netrc', '.pgpass',
            '.aws/credentials', '.docker/config.json', '.ssh/id_ed25519', '.kube/config', 'public/.htpasswd', '.git-credentials'] as $path) {
            $this->assertTrue(ReleaseRules::neverShips($path), $path);
        }

        $this->assertFalse(ReleaseRules::neverShips('.ENV.EXAMPLE'));
        $this->assertFalse(ReleaseRules::neverShips('.env.example'));
        $this->assertFalse(ReleaseRules::neverShips('config/.env.example'));

        // allow lets a name through on purpose.
        $this->assertFalse(ReleaseRules::neverShips('public/.htpasswd', ['public/.htpasswd']));
    }

    public function test_what_the_node_writes_itself_is_named(): void
    {
        foreach (['RELEASE_SHA', '.slipstream', '.slipstream/manifest.json', 'bootstrap/cache/config.php', 'bootstrap/cache/routes-v7.php'] as $path) {
            $this->assertTrue(ReleaseRules::nodeWritten($path), $path);
        }
        foreach (['bootstrap/cache/.gitignore', 'bootstrap/app.php', 'app/RELEASE_SHA'] as $path) {
            $this->assertFalse(ReleaseRules::nodeWritten($path), $path);
        }
    }

    public function test_a_key_anywhere_in_a_large_file_is_found_and_under_vendor_it_is_a_warning(): void
    {
        $root = sys_get_temp_dir().'/ss-rules-'.bin2hex(random_bytes(4));
        mkdir($root.'/public/build', 0777, true);
        mkdir($root.'/vendor/acme/pkg/tests', 0777, true);

        try {
            // Past the first megabyte, and straddling a chunk boundary.
            file_put_contents($root.'/public/build/app.js', str_repeat('x', 1048576 - 10).'-----BEGIN RSA PRIVATE KEY-----'.str_repeat('y', 4096));
            file_put_contents($root.'/vendor/acme/pkg/tests/key.pem', "-----BEGIN EC PRIVATE KEY-----\nMHc\n");
            file_put_contents($root.'/public/ok.js', str_repeat('z', 2 * 1048576));

            $inspection = ReleaseRules::inspect($root, ['public/build/app.js', 'vendor/acme/pkg/tests/key.pem', 'public/ok.js']);

            $this->assertSame(['public/build/app.js holds a private key'], $inspection['problems']);
            $this->assertSame(['vendor/acme/pkg/tests/key.pem holds a private key'], $inspection['warnings']);
        } finally {
            foreach (['public/build/app.js', 'public/ok.js', 'vendor/acme/pkg/tests/key.pem'] as $file) {
                @unlink($root.'/'.$file);
            }
            foreach (['public/build', 'public', 'vendor/acme/pkg/tests', 'vendor/acme/pkg', 'vendor/acme', 'vendor', ''] as $dir) {
                @rmdir($root.'/'.$dir);
            }
        }
    }

    public function test_key_material_is_found_by_content_and_key_stores_by_extension(): void
    {
        $this->assertSame('config/sp.key holds a private key', ReleaseRules::secretProblem('config/sp.key', "-----BEGIN RSA PRIVATE KEY-----\nMIIE\n"));
        $this->assertSame('a.pem holds a private key', ReleaseRules::secretProblem('a.pem', "x\n-----BEGIN OPENSSH PRIVATE KEY-----\n"));
        $this->assertSame('b.txt holds a private key', ReleaseRules::secretProblem('b.txt', '-----BEGIN ENCRYPTED PRIVATE KEY-----'));
        $this->assertSame('c.asc holds a private key', ReleaseRules::secretProblem('c.asc', '-----BEGIN PGP PRIVATE KEY BLOCK-----'));
        $this->assertSame('certs/client.p12 is a key store', ReleaseRules::secretProblem('certs/client.p12', null));

        // A CA bundle is a public certificate; vendor code and allowed paths pass.
        $this->assertNull(ReleaseRules::secretProblem('resources/certs/global-bundle.pem', "-----BEGIN CERTIFICATE-----\nMIIC\n"));
        $this->assertNull(ReleaseRules::secretProblem('config/public.pem', '-----BEGIN PUBLIC KEY-----'));
        $this->assertNull(ReleaseRules::secretProblem('vendor/phpseclib/tests/key.pem', '-----BEGIN RSA PRIVATE KEY-----'));
        $this->assertNull(ReleaseRules::secretProblem('config/saml/sp.key', '-----BEGIN PRIVATE KEY-----', ['config/saml']));
        $this->assertNotNull(ReleaseRules::secretProblem('config/samlx/sp.key', '-----BEGIN PRIVATE KEY-----', ['config/saml']), 'a prefix allows a directory, not a name that starts with it');
    }
}
