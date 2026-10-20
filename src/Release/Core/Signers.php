<?php

namespace Slipstream\Release\Core;

/**
 * The signer a key spec names: sodium:<base64>, file:<path>, or kms:<key>.
 */
final class Signers
{
    public static function fromSpec(string $spec, ?string $keyId = null): Signer
    {
        $spec = trim($spec);

        return match (true) {
            str_starts_with($spec, 'kms:') => KmsSigner::fromSpec($spec, $keyId),
            str_starts_with($spec, 'sodium:'), str_starts_with($spec, 'file:') => SodiumSigner::fromSpec($spec, $keyId),
            default => throw new \InvalidArgumentException('A signing key is sodium:<base64 secret>, file:<path>, or kms:<key id, ARN or alias>.'),
        };
    }
}
