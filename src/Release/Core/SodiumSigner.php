<?php

namespace Slipstream\Release\Core;

/**
 * Signs with an Ed25519 secret key held locally: `sodium:<base64 of the
 * 64-byte secret key>` or `file:<path>` holding that base64.
 */
final class SodiumSigner implements Signer
{
    public function __construct(private string $secretKey, private ?string $keyId = null)
    {
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('An Ed25519 secret key is 64 bytes.');
        }
    }

    public static function fromSpec(string $spec, ?string $keyId = null): self
    {
        $spec = trim($spec);

        if (str_starts_with($spec, 'file:')) {
            $path = substr($spec, 5);
            $contents = is_file($path) ? @file_get_contents($path) : false;
            if ($contents === false) {
                throw new \InvalidArgumentException("The signing key file {$path} cannot be read.");
            }
            $encoded = trim($contents);
            $encoded = str_starts_with($encoded, 'sodium:') ? substr($encoded, 7) : $encoded;
        } elseif (str_starts_with($spec, 'sodium:')) {
            $encoded = substr($spec, 7);
        } else {
            throw new \InvalidArgumentException('A sodium signing key is sodium:<base64> or file:<path>.');
        }

        $raw = base64_decode(trim($encoded), true);
        if ($raw === false) {
            throw new \InvalidArgumentException('The signing key is not base64.');
        }

        return new self($raw, $keyId);
    }

    /**
     * A fresh key pair, both halves base64, with the id nodes would derive.
     *
     * @return array{secret: string, public: string, key_id: string}
     */
    public static function generate(): array
    {
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);

        return [
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'public' => base64_encode($public),
            'key_id' => Keys::deriveId($public),
        ];
    }

    public function sign(string $message): string
    {
        return sodium_crypto_sign_detached($message, $this->secretKey);
    }

    public function publicKey(): string
    {
        return sodium_crypto_sign_publickey_from_secretkey($this->secretKey);
    }

    public function keyId(): string
    {
        return $this->keyId ??= Keys::deriveId($this->publicKey());
    }
}
