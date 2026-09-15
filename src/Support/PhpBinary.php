<?php

namespace Slipstream\Support;

use Closure;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The PHP CLI that detached artisan processes and deploy hooks run under.
 *
 * PHP_BINARY is the executable of the CURRENT SAPI, not "the php command".
 * From the CLI that is the CLI. From a PHP-FPM worker — every dashboard click
 * and every webhook — it is the FPM daemon (/usr/sbin/php-fpm8.4), which,
 * handed `artisan slipstream:deploy`, prints its usage and exits. The shell
 * that backgrounded it still reports success, so nothing failed loudly: the
 * row sat in "pending" until the watchdog and the detach log held php-fpm help
 * text, which reads like an FPM misconfiguration. php-cgi has the same problem.
 *
 * So the binary is resolved, never assumed:
 *   1. an explicit SLIPSTREAM_PHP / slipstream.deploy.php_binary;
 *   2. PHP_BINARY, but only when this process IS a CLI;
 *   3. the CLI installed next to the running build — same major.minor first,
 *      so a box with several PHP versions deploys under the one FPM serves,
 *      then the unversioned name (Debian's alternatives link, Docker images);
 *   4. `php` on PATH.
 *
 * Nothing that looks like a server binary is ever returned, and a miss throws
 * rather than guessing, so the caller records a failed spawn on the spot.
 *
 * Every input has a constructor override: PHP_SAPI and PHP_BINARY cannot be
 * changed from a test, and doctor runs from the CLI but needs the answer an
 * FPM worker would get.
 */
final class PhpBinary
{
    public const ORIGIN_CONFIGURED = 'configured';

    public const ORIGIN_SAPI = 'sapi';

    public const ORIGIN_DETECTED = 'detected';

    /** SAPIs whose PHP_BINARY is a CLI that can run artisan. */
    private const CLI_SAPIS = ['cli', 'cli-server', 'phpdbg'];

    private string $origin = '';

    /** @var list<string> */
    private array $tried = [];

    /**
     * @param  string|null  $configured  slipstream.deploy.php_binary (SLIPSTREAM_PHP)
     * @param  (Closure(string): bool)|null  $isExecutable  overridable for tests
     * @param  (Closure(string): ?string)|null  $findOnPath  overridable for tests
     */
    public function __construct(
        private ?string $configured = null,
        private string $sapi = PHP_SAPI,
        private string $binary = PHP_BINARY,
        private string $bindir = PHP_BINDIR,
        private string $version = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
        private ?Closure $isExecutable = null,
        private ?Closure $findOnPath = null,
    ) {}

    public static function fromConfig(): self
    {
        $configured = config('slipstream.deploy.php_binary');

        return new self(is_string($configured) ? $configured : null);
    }

    /**
     * The CLI to run artisan with, from live config and this process.
     *
     * @throws RuntimeException when nothing usable exists
     */
    public static function resolve(): string
    {
        return self::fromConfig()->path();
    }

    /**
     * @throws RuntimeException when nothing usable exists
     */
    public function path(): string
    {
        $this->tried = [];
        $this->origin = '';

        $configured = trim((string) $this->configured);

        // A config published before auto-detection reads
        // env('SLIPSTREAM_PHP', PHP_BINARY), which evaluated by an FPM worker
        // bakes the daemon in as if someone had configured it. That reads as
        // "not configured" rather than failing every dashboard and webhook
        // deploy on a stale file; doctor points the stale file out.
        if ($configured !== '' && ! self::isServerBinary($configured)) {
            $path = $this->executable($configured) ? $configured : null;

            // A bare command name (SLIPSTREAM_PHP=php8.4) is looked up on
            // PATH; a path that does not exist is a typo, not a search.
            if ($path === null && ! self::hasDirectory($configured)) {
                $path = $this->onPath($configured);
            }

            if ($path === null) {
                throw new RuntimeException(sprintf(
                    'SLIPSTREAM_PHP (slipstream.deploy.php_binary) is "%s", which is not an executable file or a command on PATH.',
                    $configured
                ));
            }

            $this->origin = self::ORIGIN_CONFIGURED;

            return $path;
        }

        if (in_array($this->sapi, self::CLI_SAPIS, true)
            && $this->binary !== ''
            && ! self::isServerBinary($this->binary)
            && $this->executable($this->binary)
        ) {
            $this->origin = self::ORIGIN_SAPI;

            return $this->binary;
        }

        foreach ($this->candidates() as $candidate) {
            $this->tried[] = $candidate;

            if (! self::isServerBinary($candidate) && $this->executable($candidate)) {
                $this->origin = self::ORIGIN_DETECTED;

                return $candidate;
            }
        }

        $this->tried[] = 'php on PATH';
        $found = $this->onPath('php');

        if ($found !== null && ! self::isServerBinary($found)) {
            $this->origin = self::ORIGIN_DETECTED;

            return $found;
        }

        $context = $this->binary !== '' && ! in_array($this->sapi, self::CLI_SAPIS, true)
            ? sprintf(' This process is the %s SAPI running as %s, which cannot run artisan.', $this->sapi, $this->binary)
            : '';

        throw new RuntimeException(sprintf(
            'No PHP CLI binary found to run artisan with (tried %s).%s Set SLIPSTREAM_PHP=/path/to/php — the CLI, never php-fpm — in the shared .env.',
            implode(', ', $this->tried),
            $context
        ));
    }

    /**
     * Where path() found its answer: one of the ORIGIN_* constants.
     */
    public function origin(): string
    {
        return $this->origin;
    }

    /**
     * php-fpm or php-cgi in any packaged spelling: php-fpm, php-fpm8.4
     * (Debian), php84-fpm, php-fpm.exe, php-cgi …
     */
    public static function isServerBinary(string $path): bool
    {
        $name = strtolower(basename(str_replace('\\', '/', trim($path))));

        return preg_match('/^php[\d.]*-(fpm|cgi)(?![a-z])/', $name) === 1;
    }

    /**
     * @return list<string>
     */
    private function candidates(): array
    {
        $dir = rtrim($this->bindir, '/\\');
        if ($dir === '') {
            return [];
        }

        $candidates = [];
        foreach (['php'.$this->version, 'php'] as $name) {
            $candidates[] = $dir.DIRECTORY_SEPARATOR.$name;
            if (DIRECTORY_SEPARATOR === '\\') {
                $candidates[] = $dir.DIRECTORY_SEPARATOR.$name.'.exe';
            }
        }

        return $candidates;
    }

    private function executable(string $path): bool
    {
        if ($this->isExecutable !== null) {
            return (bool) ($this->isExecutable)($path);
        }

        return @is_file($path) && @is_executable($path);
    }

    private function onPath(string $name): ?string
    {
        if ($this->findOnPath !== null) {
            return ($this->findOnPath)($name);
        }

        // FPM clears its environment by default, so PATH is usually empty in
        // the process asking; the extra directories are the PATH the detached
        // child is given (ProcessDetacher::environment()).
        $extra = [$this->bindir, '/usr/local/bin', '/usr/bin', '/bin'];
        if ($herd = getenv('HERD_HOME')) {
            $extra[] = $herd.DIRECTORY_SEPARATOR.'bin';
        }

        return (new ExecutableFinder)->find($name, null, array_values(array_unique(array_filter($extra))));
    }

    private static function hasDirectory(string $value): bool
    {
        return str_contains($value, '/') || str_contains($value, '\\');
    }
}
