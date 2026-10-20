<?php

namespace Slipstream\Tests\Unit;

use InvalidArgumentException;
use RuntimeException;
use Slipstream\Release\Core\Envelope;
use Slipstream\Release\Core\Keys;
use Slipstream\Release\Core\KmsSigner;
use Slipstream\Release\Core\Signers;
use Slipstream\Release\Core\SodiumSigner;
use Slipstream\Tests\TestCase;

/**
 * KMS signs through the AWS CLI. A fake CLI, backed by a sodium key, plays
 * the part: what matters here is what we send it and what we make of what
 * it returns.
 */
class KmsSignerTest extends TestCase
{
    /** @var list<list<string>> */
    protected array $calls = [];

    protected function fakeAws(string $secret, string $spki): \Closure
    {
        return function (array $argv) use ($secret, $spki): string {
            $this->calls[] = $argv;

            if ($argv[2] === 'sign') {
                $file = substr($argv[array_search('--message', $argv, true) + 1], strlen('fileb://'));

                return json_encode(['KeyId' => 'arn', 'Signature' => base64_encode(sodium_crypto_sign_detached((string) file_get_contents($file), $secret)), 'SigningAlgorithm' => 'ED25519_SHA_512']);
            }

            return json_encode(['KeyId' => 'arn', 'PublicKey' => base64_encode($spki), 'KeySpec' => 'ECC_NIST_EDWARDS25519']);
        };
    }

    public function test_it_strips_the_spki_prefix_and_passes_the_signature_through(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        $signer = new KmsSigner('alias/app-release', null, $this->fakeAws($secret, hex2bin(KmsSigner::SPKI_PREFIX).$public));

        $this->assertSame($public, $signer->publicKey());
        $this->assertSame(Keys::deriveId($public), $signer->keyId());

        $envelope = Envelope::encode(['sha' => 'x'], Envelope::DOMAIN_MANIFEST, $signer);
        $verified = Envelope::verify($envelope, Envelope::DOMAIN_MANIFEST, Keys::parse(Keys::format($signer->keyId(), $public)));
        $this->assertSame(['sha' => 'x'], $verified['payload']);

        $sign = $this->calls[1];
        $this->assertSame(['aws', 'kms', 'sign', '--key-id', 'alias/app-release', '--signing-algorithm', 'ED25519_SHA_512', '--message-type', 'RAW'], array_slice($sign, 0, 9));
        $this->assertFileDoesNotExist(substr($sign[10], strlen('fileb://')), 'the message file is removed');
    }

    public function test_a_key_that_is_not_ed25519_is_refused(): void
    {
        $signer = new KmsSigner('alias/rsa', null, $this->fakeAws(str_repeat('s', 64), str_repeat('x', 294)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an Ed25519 key');

        $signer->publicKey();
    }

    public function test_a_failing_cli_is_reported_with_what_it_said(): void
    {
        $signer = new KmsSigner('alias/app-release', 'prod', fn (array $argv) => throw new RuntimeException('aws kms sign failed (exit 254): AccessDeniedException'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AccessDeniedException');

        $signer->sign('x');
    }

    public function test_a_message_over_the_raw_limit_never_reaches_kms(): void
    {
        $signer = new KmsSigner('alias/app-release', 'prod', fn (array $argv) => $this->fail('called the CLI'));

        $this->expectException(InvalidArgumentException::class);

        $signer->sign(str_repeat('x', 4097));
    }

    public function test_specs_name_the_signer(): void
    {
        $pair = SodiumSigner::generate();

        $this->assertInstanceOf(SodiumSigner::class, Signers::fromSpec('sodium:'.$pair['secret']));
        $this->assertInstanceOf(KmsSigner::class, Signers::fromSpec('kms:alias/x'));

        foreach (['', 'pgp:x', 'kms:', 'sodium:not base64!'] as $spec) {
            try {
                Signers::fromSpec($spec);
                $this->fail("accepted \"{$spec}\"");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
