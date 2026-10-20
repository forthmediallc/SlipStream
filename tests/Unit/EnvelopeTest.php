<?php

namespace Slipstream\Tests\Unit;

use InvalidArgumentException;
use Slipstream\Release\Core\Envelope;
use Slipstream\Release\Core\Keys;
use Slipstream\Release\Core\SignatureException;
use Slipstream\Release\Core\SodiumSigner;
use Slipstream\Tests\TestCase;

/**
 * The signed envelope every pointer and manifest statement travels in.
 */
class EnvelopeTest extends TestCase
{
    protected SodiumSigner $signer;

    protected Keys $keys;

    protected function setUp(): void
    {
        parent::setUp();

        $pair = SodiumSigner::generate();
        $this->signer = SodiumSigner::fromSpec('sodium:'.$pair['secret'], 'prod-2026');
        $this->keys = Keys::parse('prod-2026:'.$pair['public']);
    }

    public function test_a_payload_signed_with_sodium_verifies_and_comes_back_intact(): void
    {
        $payload = ['sha' => str_repeat('a', 40), 'sequence' => 7, 'previous' => ['sha' => str_repeat('b', 40)]];

        $envelope = Envelope::encode($payload, Envelope::DOMAIN_POINTER, $this->signer);
        $verified = Envelope::verify($envelope, Envelope::DOMAIN_POINTER, $this->keys);

        $this->assertEquals($payload, $verified['payload']); // keys come back sorted: the signed bytes are canonical
        $this->assertSame('prod-2026', $verified['key_id']);
        $this->assertSame('ed25519', json_decode($envelope, true)['alg']);
    }

    public function test_the_signed_bytes_are_canonical_but_verification_uses_the_bytes_as_sent(): void
    {
        $this->assertSame(
            '{"a":{"y":1,"z":[2,1]},"b":"/x"}',
            Envelope::payloadBytes(['b' => '/x', 'a' => ['z' => [2, 1], 'y' => 1]])
        );
    }

    public function test_a_wrong_key_is_refused(): void
    {
        $other = SodiumSigner::generate();
        $envelope = Envelope::encode(['sha' => 'x'], Envelope::DOMAIN_POINTER, SodiumSigner::fromSpec('sodium:'.$other['secret'], 'prod-2026'));

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('signature does not verify');

        Envelope::verify($envelope, Envelope::DOMAIN_POINTER, $this->keys);
    }

    public function test_a_pointer_cannot_pass_as_a_manifest_statement(): void
    {
        $envelope = Envelope::encode(['sha' => 'x'], Envelope::DOMAIN_POINTER, $this->signer);

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('signature does not verify');

        Envelope::verify($envelope, Envelope::DOMAIN_MANIFEST, $this->keys);
    }

    public function test_an_unknown_key_id_is_refused_and_names_the_configured_ids(): void
    {
        $envelope = Envelope::encode(['sha' => 'x'], Envelope::DOMAIN_POINTER, $this->signer);
        $pair = SodiumSigner::generate();
        $keys = Keys::parse(['a:'.$pair['public'], 'b:'.$pair['public']]);

        try {
            Envelope::verify($envelope, Envelope::DOMAIN_POINTER, $keys);
            $this->fail('verified under keys that do not hold prod-2026');
        } catch (SignatureException $e) {
            $this->assertSame('unknown key id "prod-2026" (configured: a, b)', $e->getMessage());
        }
    }

    public function test_tampering_is_refused_for_the_reason_it_was_tampered(): void
    {
        $envelope = Envelope::encode(['sha' => 'x'], Envelope::DOMAIN_POINTER, $this->signer);
        $decoded = json_decode($envelope, true);

        foreach ([
            [['alg' => 'rsa'], 'alg is not ed25519'],
            [['payload' => base64_encode('{"sha":"y"}')], 'signature does not verify'],
            [['signature' => base64_encode(str_repeat("\0", 64))], 'signature does not verify'],
            [['signature' => 'not base64!'], 'not a signature envelope'],
            [['payload' => null], 'not a signature envelope'],
        ] as [$change, $reason]) {
            try {
                Envelope::verify(json_encode(array_merge($decoded, $change)), Envelope::DOMAIN_POINTER, $this->keys);
                $this->fail("accepted an envelope with {$reason}");
            } catch (SignatureException $e) {
                $this->assertSame($reason, $e->getMessage());
            }
        }

        foreach (['', 'null', '[]', '"x"', str_repeat('{', 70000)] as $junk) {
            try {
                Envelope::verify($junk, Envelope::DOMAIN_POINTER, $this->keys);
                $this->fail('accepted junk');
            } catch (SignatureException $e) {
                $this->assertSame('not a signature envelope', $e->getMessage());
            }
        }
    }

    public function test_a_payload_over_the_kms_limit_is_refused_at_signing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('payload exceeds 4096 bytes');

        Envelope::encode(['pad' => str_repeat('x', 4096)], Envelope::DOMAIN_POINTER, $this->signer);
    }

    public function test_public_keys_parse_id_and_base64_pairs_with_the_limits_a_rotation_needs(): void
    {
        $a = SodiumSigner::generate();
        $b = SodiumSigner::generate();

        $keys = Keys::parse(" a:{$a['public']} , b:{$b['public']}\n");
        $this->assertSame(['a', 'b'], $keys->ids());
        $this->assertSame(base64_decode($b['public']), $keys->find('b'));
        $this->assertNull($keys->find('c'));

        foreach ([
            "a:{$a['public']},b:{$b['public']},c:{$a['public']}" => 'At most 2 public keys',
            'a:'.base64_encode(str_repeat('k', 31)) => 'not a base64 Ed25519 key',
            "a:{$a['public']},a:{$b['public']}" => 'listed twice',
            'no-colon' => 'not a public key entry',
            "bad id!:{$a['public']}" => 'not a public key entry',
        ] as $entries => $message) {
            try {
                Keys::parse($entries);
                $this->fail("accepted {$entries}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $file = tempnam(sys_get_temp_dir(), 'ss-keys-');
        file_put_contents($file, "a:{$a['public']}\nb:{$b['public']}\n");
        try {
            $this->assertSame(['a', 'b'], Keys::parse('file:'.$file)->ids());
        } finally {
            @unlink($file);
        }

        // Relative resolves against the working directory — for artisan, the
        // release: trust anchors from the archive they are meant to check.
        try {
            Keys::parse('file:release-keys');
            $this->fail('read the keys from a relative path');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('is a relative path', $e->getMessage());
        }

        $this->assertSame(substr(hash('sha256', base64_decode($a['public'])), 0, 8), $a['key_id']);
        $this->assertSame('x:'.$a['public'], Keys::format('x', base64_decode($a['public'])));
    }
}
