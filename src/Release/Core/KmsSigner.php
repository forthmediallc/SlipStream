<?php

namespace Slipstream\Release\Core;

/**
 * Signs with an AWS KMS asymmetric key (spec ECC_NIST_EDWARDS25519) through
 * the AWS CLI, so the private key never leaves KMS and every signature is a
 * CloudTrail event. `kms:<key id, ARN or alias>`. The algorithm is
 * ED25519_SHA_512 over the RAW message, which KMS caps at 4096 bytes — the
 * reason every signed payload stays small and the manifest is bound by hash.
 */
final class KmsSigner implements Signer
{
    public const ALGORITHM = 'ED25519_SHA_512';

    public const MAX_MESSAGE = 4096;

    /** The DER SubjectPublicKeyInfo prefix of an Ed25519 key: 12 bytes, then the 32-byte key. */
    public const SPKI_PREFIX = '302a300506032b6570032100';

    /** @var (\Closure(list<string>): string)|null */
    private ?\Closure $run;

    private ?string $publicKey = null;

    /**
     * @param  (\Closure(list<string>): string)|null  $run  runs an argv, returns stdout, throws on failure; the real CLI when null
     */
    public function __construct(private string $key, private ?string $keyId = null, ?\Closure $run = null)
    {
        $this->run = $run;
    }

    public static function fromSpec(string $spec, ?string $keyId = null, ?\Closure $run = null): self
    {
        $spec = trim($spec);
        if (! str_starts_with($spec, 'kms:') || trim(substr($spec, 4)) === '') {
            throw new \InvalidArgumentException('A KMS signing key is kms:<key id, ARN or alias>.');
        }

        return new self(trim(substr($spec, 4)), $keyId, $run);
    }

    public function sign(string $message): string
    {
        if (strlen($message) > self::MAX_MESSAGE) {
            throw new \InvalidArgumentException('KMS signs at most '.self::MAX_MESSAGE.' bytes of raw message.');
        }

        $file = tempnam(sys_get_temp_dir(), 'ss-sign-');
        if ($file === false || file_put_contents($file, $message) !== strlen($message)) {
            throw new \RuntimeException('Cannot write the message to sign to a temporary file.');
        }

        try {
            $out = $this->aws(['kms', 'sign', '--key-id', $this->key, '--signing-algorithm', self::ALGORITHM,
                '--message-type', 'RAW', '--message', 'fileb://'.$file, '--output', 'json']);
        } finally {
            @unlink($file);
        }

        $signature = base64_decode((string) (self::decode($out)['Signature'] ?? ''), true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new \RuntimeException('aws kms sign returned no 64-byte signature; is the key an ECC_NIST_EDWARDS25519 key?');
        }

        return $signature;
    }

    public function publicKey(): string
    {
        if ($this->publicKey !== null) {
            return $this->publicKey;
        }

        $der = base64_decode((string) (self::decode($this->aws(['kms', 'get-public-key', '--key-id', $this->key, '--output', 'json']))['PublicKey'] ?? ''), true);
        $prefix = hex2bin(self::SPKI_PREFIX);

        if ($der === false || strlen($der) !== 12 + SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || substr($der, 0, 12) !== $prefix) {
            throw new \RuntimeException('The KMS key is not an Ed25519 key: expected a 44-byte SubjectPublicKeyInfo with the Ed25519 OID.');
        }

        return $this->publicKey = substr($der, 12);
    }

    public function keyId(): string
    {
        return $this->keyId ??= Keys::deriveId($this->publicKey());
    }

    /**
     * @param  list<string>  $args
     */
    private function aws(array $args): string
    {
        $run = $this->run ?? self::cli(...);

        return $run(['aws', ...$args]);
    }

    /**
     * Run an argv without a shell; stdout on success, an exception carrying
     * stderr otherwise.
     *
     * @param  list<string>  $argv
     */
    public static function cli(array $argv): string
    {
        $process = @proc_open($argv, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new \RuntimeException('Cannot run '.$argv[0].'; is the AWS CLI installed?');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            throw new \RuntimeException(implode(' ', array_slice($argv, 0, 3)).' failed (exit '.$code.'): '.trim($stderr));
        }

        return $stdout;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('The AWS CLI returned something other than JSON: '.substr(trim($json), 0, 200));
        }

        return $decoded;
    }
}
