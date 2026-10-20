<?php

namespace Slipstream\Release\Core;

/**
 * Something that signs a message with an Ed25519 key: a sodium secret key, or
 * AWS KMS through its CLI. Nodes never sign; they verify with the public key.
 */
interface Signer
{
    /**
     * The raw 64-byte Ed25519 signature of the message.
     */
    public function sign(string $message): string;

    /**
     * The raw 32-byte public key.
     */
    public function publicKey(): string;

    /**
     * The id nodes look the key up by: given, or derived from the public key.
     */
    public function keyId(): string;
}
