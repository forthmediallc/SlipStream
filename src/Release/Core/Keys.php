<?php

namespace Slipstream\Release\Core;

/**
 * The public keys a node trusts, as SLIPSTREAM_RELEASE_PUBLIC_KEYS gives
 * them: `id:base64` entries, comma-separated, at most two so a key can be
 * rotated (add the new one, switch CI, drop the old). `file:<path>` names a
 * file holding the same list, for a key baked into an image.
 */
final class Keys
{
    public const MAX = 2;

    /**
     * @param  array<string, string>  $keys  id => raw 32-byte key
     */
    private function __construct(private array $keys) {}

    /**
     * @param  list<string>|string  $entries
     */
    public static function parse(array|string $entries, int $max = self::MAX): self
    {
        if (is_string($entries)) {
            $entries = trim($entries);
            if (str_starts_with($entries, 'file:')) {
                $path = substr($entries, 5);
                $contents = is_file($path) ? @file_get_contents($path) : false;
                if ($contents === false) {
                    throw new \InvalidArgumentException("SLIPSTREAM_RELEASE_PUBLIC_KEYS names {$path}, which cannot be read.");
                }
                $entries = $contents;
            }
            $entries = preg_split('/[\s,]+/', $entries) ?: [];
        }

        $keys = [];

        foreach ($entries as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }

            [$id, $encoded] = array_pad(explode(':', $entry, 2), 2, null);
            if ($encoded === null || preg_match('/^[A-Za-z0-9._-]{1,64}$/', (string) $id) !== 1) {
                throw new \InvalidArgumentException("\"{$entry}\" is not a public key entry; use id:base64 (slipstream:artifact keygen prints one).");
            }

            $raw = base64_decode($encoded, true);
            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new \InvalidArgumentException("The public key \"{$id}\" is not a base64 Ed25519 key (32 bytes).");
            }
            if (isset($keys[$id])) {
                throw new \InvalidArgumentException("The public key id \"{$id}\" is listed twice.");
            }

            $keys[$id] = $raw;
        }

        if (count($keys) > $max) {
            throw new \InvalidArgumentException('At most '.$max.' public keys: the one in use and the one it is rotating to.');
        }

        return new self($keys);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * The id a key gets when nobody names it: the first 8 hex characters of
     * the public key's SHA-256.
     */
    public static function deriveId(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, 8);
    }

    /**
     * One entry as SLIPSTREAM_RELEASE_PUBLIC_KEYS wants it.
     */
    public static function format(string $id, string $publicKey): string
    {
        return $id.':'.base64_encode($publicKey);
    }

    public function find(string $id): ?string
    {
        return $this->keys[$id] ?? null;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->keys);
    }

    public function count(): int
    {
        return count($this->keys);
    }

    /**
     * These keys plus one more (the publisher verifies what it just signed
     * with its own key, which the node list need not hold yet).
     */
    public function with(string $id, string $publicKey): self
    {
        return new self($this->keys + [$id => $publicKey]);
    }
}
