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
