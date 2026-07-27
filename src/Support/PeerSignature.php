<?php

namespace Slipstream\Support;

/**
 * Shared HMAC scheme for signed server-to-server POSTs (relay fan-out and env
 * sync nudges). Every payload is domain-separated by a version tag and bound to
 * a timestamp, so a signature minted for one channel (or a plain provider HMAC)
 * can never be replayed on another, and old captures fall outside the skew
 * window. Both callers HMAC the exact same bytes via payload().
 */
final class PeerSignature
{
    /**
     * The exact bytes both sides HMAC: version tag + timestamp + body.
     */
    public static function payload(string $version, string $timestamp, string $body): string
    {
        return $version."\n".$timestamp."\n".$body;
    }

    /**
     * `sha256=<hex>` signature header value for a body.
     */
    public static function sign(string $version, string $timestamp, string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', self::payload($version, $timestamp, $body), $secret);
    }

    /**
     * Constant-time verification of a signature header against the recomputed
     * HMAC, rejecting empty inputs and timestamps outside the skew window.
     */
    public static function verify(
        string $version,
        string $body,
        string $secret,
        string $signatureHeader,
        string $timestamp,
        int $maxSkewSeconds
    ): bool {
        if ($secret === '' || $signatureHeader === '' || $timestamp === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        // Replay window: reject signatures whose timestamp is too old/new.
        if (abs(time() - (int) $timestamp) > $maxSkewSeconds) {
            return false;
        }

        $sig = str_starts_with($signatureHeader, 'sha256=') ? substr($signatureHeader, 7) : $signatureHeader;
        $expected = hash_hmac('sha256', self::payload($version, $timestamp, $body), $secret);

        return hash_equals($expected, $sig);
    }
}
