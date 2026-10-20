<?php

namespace Slipstream\Release\Core;

/**
 * A signed document: {"alg":"ed25519","key_id":…,"payload":<base64 of the
 * exact JSON bytes signed>,"signature":<base64>}. The signature covers a
 * domain prefix plus the payload bytes, so a pointer can never pass as a
 * manifest statement or the other way round; verification uses the bytes as
 * sent, so there is no canonical form to disagree about. The payload stays
 * under KMS's 4096-byte raw-message cap, which is why the manifest itself is
 * bound by hash rather than signed.
 */
final class Envelope
{
    public const DOMAIN_POINTER = "slipstream-pointer\n";

    public const DOMAIN_MANIFEST = "slipstream-manifest\n";

    public const MAX_PAYLOAD = KmsSigner::MAX_MESSAGE;

    public const MAX_ENVELOPE = 65536;

    /**
     * The bytes a payload is signed as: keys sorted at every level, slashes
     * unescaped, no whitespace.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function payloadBytes(array $payload): string
    {
        return json_encode(self::sorted($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Sign a payload under a domain; returns the envelope's JSON.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function encode(array $payload, string $domain, Signer $signer): string
    {
        $bytes = self::payloadBytes($payload);

        if (strlen($bytes) > self::MAX_PAYLOAD) {
            throw new \InvalidArgumentException('payload exceeds '.self::MAX_PAYLOAD.' bytes');
        }

        return json_encode([
            'alg' => 'ed25519',
            'key_id' => $signer->keyId(),
            'payload' => base64_encode($bytes),
            'signature' => base64_encode($signer->sign($domain.$bytes)),
        ], JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * Verify an envelope under a domain against the trusted keys.
     *
     * @return array{payload: array<string, mixed>, key_id: string}
     *
     * @throws SignatureException with the one reason it does not verify
     */
    public static function verify(string $envelope, string $domain, Keys $keys): array
    {
        if (strlen($envelope) > self::MAX_ENVELOPE) {
            throw new SignatureException('not a signature envelope');
        }

        $decoded = json_decode($envelope, true);
        if (! is_array($decoded)
            || ! is_string($decoded['payload'] ?? null)
            || ! is_string($decoded['signature'] ?? null)
            || ! is_string($decoded['key_id'] ?? null)) {
            throw new SignatureException('not a signature envelope');
        }

        if (($decoded['alg'] ?? null) !== 'ed25519') {
            throw new SignatureException('alg is not ed25519');
        }

        $bytes = base64_decode($decoded['payload'], true);
        $signature = base64_decode($decoded['signature'], true);
        if ($bytes === false || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new SignatureException('not a signature envelope');
        }

        if (strlen($bytes) > self::MAX_PAYLOAD) {
            throw new SignatureException('payload exceeds '.self::MAX_PAYLOAD.' bytes');
        }

        $publicKey = $keys->find($decoded['key_id']);
        if ($publicKey === null) {
            throw new SignatureException(sprintf(
                'unknown key id "%s" (configured: %s)',
                $decoded['key_id'],
                $keys->count() > 0 ? implode(', ', $keys->ids()) : 'none'
            ));
        }

        if (! sodium_crypto_sign_verify_detached($signature, $domain.$bytes, $publicKey)) {
            throw new SignatureException('signature does not verify');
        }

        $payload = json_decode($bytes, true);
        if (! is_array($payload)) {
            throw new SignatureException('payload is not JSON');
        }

        return ['payload' => $payload, 'key_id' => $decoded['key_id']];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sorted($item);
            }
        }

        return $value;
    }
}
