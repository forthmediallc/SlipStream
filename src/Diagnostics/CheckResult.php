<?php

namespace Slipstream\Diagnostics;

/**
 * One diagnostic answer: what was checked, how it went, and what to do about
 * it. `fix` is the whole point — a check that reports a problem without naming
 * the remedy just moves the guessing somewhere else.
 */
class CheckResult
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public const SKIP = 'skip';

    public function __construct(
        public readonly string $status,
        public readonly string $label,
        public readonly string $message,
        public readonly ?string $fix = null,
    ) {}

    public static function pass(string $label, string $message): self
    {
        return new self(self::PASS, $label, $message);
    }

    public static function warn(string $label, string $message, ?string $fix = null): self
    {
        return new self(self::WARN, $label, $message, $fix);
    }

    public static function fail(string $label, string $message, ?string $fix = null): self
    {
        return new self(self::FAIL, $label, $message, $fix);
    }

    public static function skip(string $label, string $message): self
    {
        return new self(self::SKIP, $label, $message);
    }

    public function isFailure(): bool
    {
        return $this->status === self::FAIL;
    }

    public function color(): string
    {
        return match ($this->status) {
            self::PASS => 'green',
            self::WARN => 'yellow',
            self::FAIL => 'red',
            default => 'gray',
        };
    }
}
