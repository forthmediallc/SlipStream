<?php

namespace Slipstream\Webhooks;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;
use Slipstream\Support\AuditLog;

/**
 * The git hosts' published webhook ranges, for the keywords
 * SLIPSTREAM_WEBHOOK_ALLOWED_IPS accepts beside addresses: github and
 * bitbucket. (GitLab.com publishes no hook ranges, so there is no gitlab.)
 *
 * Downloaded from the minute heartbeat every few hours with an ETag, so an
 * unchanged list costs no rate limit — GitHub allows 60 unauthenticated
 * requests an hour per address. A failed download keeps the last good list.
 * A keyword never downloaded matches nothing: every delivery is refused until
 * the first download rather than let through, doctor fails on that, and warns
 * once the list is old.
 */
final class WebhookSourceRanges
{
    public const SETTING = 'webhook_ranges';

    public const PROVIDERS = ['github', 'bitbucket'];

    public const GITHUB_URL = 'https://api.github.com/meta';

    public const BITBUCKET_URL = 'https://ip-ranges.atlassian.com/';

    /**
     * Minutes without a new attempt after a failed one: a provider outage
     * must not turn the minute heartbeat into 60 requests an hour.
     */
    public const RETRY_MINUTES = 15;

    public function __construct(protected SettingsRepository $settings) {}

    public static function isKeyword(string $entry): bool
    {
        return in_array(strtolower(trim($entry)), self::PROVIDERS, true);
    }

    public static function isAddress(string $entry): bool
    {
        [$address, $prefix] = array_pad(explode('/', trim($entry), 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return $prefix === null
            || (ctype_digit($prefix) && (int) $prefix <= (str_contains($address, ':') ? 128 : 32));
    }

    /**
     * @param  list<string>  $configured
     * @return array{keywords: list<string>, literals: list<string>, unknown: list<string>}
     */
    public static function classify(array $configured): array
    {
        $out = ['keywords' => [], 'literals' => [], 'unknown' => []];

        foreach ($configured as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }

            if (self::isKeyword($entry)) {
                $out['keywords'][] = strtolower($entry);
            } elseif (self::isAddress($entry)) {
                $out['literals'][] = $entry;
            } else {
                $out['unknown'][] = $entry;
            }
        }

        $out['keywords'] = array_values(array_unique($out['keywords']));

        return $out;
    }

    /**
     * The effective allow-list for a configured list, and what it lacks:
     * keywords never downloaded (`missing`) and keywords whose list is older
     * than ranges_stale_hours (`stale`). Reads the settings table only when a
     * keyword is configured.
     *
     * @param  list<string>  $configured
     * @return array{cidrs: list<string>, keywords: list<string>, literals: list<string>, unknown: list<string>, missing: list<string>, stale: list<string>}
     */
    public function resolve(array $configured): array
    {
        $classified = self::classify($configured);
        $cidrs = $classified['literals'];
        $missing = [];
        $stale = [];

        if ($classified['keywords'] !== []) {
            $stored = $this->stored();

            foreach ($classified['keywords'] as $keyword) {
                $entry = $stored[$keyword] ?? null;
                $list = is_array($entry) && is_array($entry['cidrs'] ?? null)
                    ? array_values(array_filter($entry['cidrs'], 'is_string'))
                    : [];

                if ($list === []) {
                    $missing[] = $keyword;

                    continue;
                }

                $cidrs = [...$cidrs, ...$list];
                if ($this->isStale($keyword)) {
                    $stale[] = $keyword;
                }
            }
        }

        return $classified + ['cidrs' => array_values(array_unique($cidrs)), 'missing' => $missing, 'stale' => $stale];
    }

    /**
     * What is stored per keyword: cidrs, etag, fetched_at, last_attempt_at,
     * last_error. A corrupt value reads as nothing stored, never as "any".
     *
     * @return array<string, array<string, mixed>>
     */
    public function stored(): array
    {
        $value = $this->settings->get(self::SETTING);

        return is_array($value) ? array_filter($value, 'is_array') : [];
    }

    /**
     * Due when never downloaded or older than ranges_refresh_hours, unless a
     * failed attempt was within RETRY_MINUTES.
     */
    public function isDue(string $keyword): bool
    {
        $entry = $this->stored()[$keyword] ?? [];

        $attempted = self::when($entry['last_attempt_at'] ?? null);
        if ($attempted !== null && ($entry['last_error'] ?? null) !== null
            && $attempted->gt(now()->subMinutes(self::RETRY_MINUTES))) {
            return false;
        }

        $fetched = self::when($entry['fetched_at'] ?? null);
        $hours = max(1, (int) config('slipstream.webhook.ranges_refresh_hours', 6));

        return $fetched === null || $fetched->lt(now()->subHours($hours));
    }

    public function isStale(string $keyword): bool
    {
        $fetched = self::when($this->stored()[$keyword]['fetched_at'] ?? null);
        $hours = max(1, (int) config('slipstream.webhook.ranges_stale_hours', 48));

        return $fetched !== null && $fetched->lt(now()->subHours($hours));
    }

    /**
     * Download every configured keyword that is due. Nothing configured, or
     * nothing due: no request at all.
     *
     * @param  list<string>|null  $configured  default: SLIPSTREAM_WEBHOOK_ALLOWED_IPS
     * @return list<array{keyword: string, ok: bool, count: int, changed: bool, detail: string}>
     */
    public function refreshIfDue(?array $configured = null, string $via = 'heartbeat'): array
    {
        $results = [];

        foreach (self::classify($configured ?? Slipstream::webhookAllowedIps())['keywords'] as $keyword) {
            if ($this->isDue($keyword)) {
                $results[] = $this->refresh($keyword, $via);
            }
        }

        return $results;
    }

    /**
     * Download one provider's ranges now. A failure keeps the stored list and
     * is audited every time; the activity log gets the first failure of a
     * streak only, so a long outage does not flood it.
     *
     * @return array{keyword: string, ok: bool, count: int, changed: bool, detail: string}
     */
    public function refresh(string $keyword, string $via = 'cli'): array
    {
        $keyword = strtolower(trim($keyword));
        if (! in_array($keyword, self::PROVIDERS, true)) {
            throw new InvalidArgumentException("\"{$keyword}\" is not a provider keyword; one of ".implode(', ', self::PROVIDERS).'.');
        }

        $stored = $this->stored();
        $entry = $stored[$keyword] ?? [];
        $kept = is_array($entry['cidrs'] ?? null) ? count($entry['cidrs']) : 0;

        // GitHub's API refuses a request without a User-Agent; the ETag makes
        // an unchanged list a 304, which does not count against its limit.
        $headers = ['User-Agent' => 'Slipstream', 'Accept' => 'application/json'];
        if (is_string($entry['etag'] ?? null) && $entry['etag'] !== '') {
            $headers['If-None-Match'] = $entry['etag'];
        }

        try {
            $response = Http::timeout(10)->withHeaders($headers)->get($keyword === 'github' ? self::GITHUB_URL : self::BITBUCKET_URL);

            if ($response->status() === 304) {
                $entry['fetched_at'] = now()->toIso8601String();
                $entry['last_attempt_at'] = now()->toIso8601String();
                $entry['last_error'] = null;
                $this->store($stored, $keyword, $entry);

                return ['keyword' => $keyword, 'ok' => true, 'count' => $kept, 'changed' => false, 'detail' => "unchanged ({$kept} ranges)"];
            }

            if (! $response->successful()) {
                throw new RuntimeException('HTTP '.$response->status());
            }

            $cidrs = self::extract($keyword, $response->json());
            $changed = $cidrs !== array_values(array_filter((array) ($entry['cidrs'] ?? []), 'is_string'));

            $this->store($stored, $keyword, [
                'cidrs' => $cidrs,
                'etag' => $response->header('ETag') ?: null,
                'fetched_at' => now()->toIso8601String(),
                'last_attempt_at' => now()->toIso8601String(),
                'last_error' => null,
            ]);

            if ($changed) {
                Activity::log('webhook.ranges_updated', ['keyword' => $keyword, 'ranges' => count($cidrs), 'via' => $via]);
            }

            return ['keyword' => $keyword, 'ok' => true, 'count' => count($cidrs), 'changed' => $changed, 'detail' => count($cidrs).' ranges ('.($changed ? 'changed' : 'unchanged').')'];
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 255);
            $firstOfStreak = ($entry['last_error'] ?? null) === null;

            $entry['last_attempt_at'] = now()->toIso8601String();
            $entry['last_error'] = $error;
            $this->store($stored, $keyword, $entry);

            AuditLog::record('webhook.ranges_refresh_failed', ['keyword' => $keyword, 'error' => $error, 'kept' => $kept, 'fetched_at' => $entry['fetched_at'] ?? null, 'via' => $via]);
            if ($firstOfStreak) {
                Activity::log('webhook.ranges_refresh_failed', ['keyword' => $keyword, 'error' => $error, 'kept' => $kept, 'via' => $via]);
            }

            return [
                'keyword' => $keyword,
                'ok' => false,
                'count' => $kept,
                'changed' => false,
                'detail' => $error.($kept > 0 ? "; kept {$kept} ranges from ".self::ago($entry['fetched_at'] ?? null) : '; nothing to keep — every delivery is refused'),
            ];
        }
    }

    /**
     * One line on a keyword's state, for doctor and the command.
     */
    public function describe(string $keyword): string
    {
        $entry = $this->stored()[$keyword] ?? [];
        $count = is_array($entry['cidrs'] ?? null) ? count($entry['cidrs']) : 0;

        if ($count === 0) {
            return 'never downloaded — every delivery is refused until it is'
                .(($entry['last_error'] ?? null) !== null ? '; last attempt failed: '.$entry['last_error'] : '');
        }

        return "{$count} ranges · downloaded ".self::ago($entry['fetched_at'] ?? null)
            .(($entry['last_error'] ?? null) !== null ? '; last attempt failed: '.$entry['last_error'] : '');
    }

    public static function ago(mixed $iso): string
    {
        $when = self::when($iso);

        return $when === null ? 'never' : $when->diffForHumans();
    }

    protected static function when(mixed $iso): ?Carbon
    {
        if (! is_string($iso) || $iso === '') {
            return null;
        }

        try {
            return Carbon::parse($iso);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The hook ranges in a provider's document: GitHub lists them under
     * "hooks"; Atlassian lists every product and direction, of which the
     * webhooks are Bitbucket's egress.
     *
     * @return list<string>
     */
    protected static function extract(string $keyword, mixed $json): array
    {
        $candidates = [];

        if ($keyword === 'github') {
            $candidates = is_array($json['hooks'] ?? null) ? $json['hooks'] : [];
        } else {
            foreach (is_array($json['items'] ?? null) ? $json['items'] : [] as $item) {
                $products = array_map('strtolower', array_filter((array) ($item['product'] ?? []), 'is_string'));
                $directions = array_map('strtolower', array_filter((array) ($item['direction'] ?? []), 'is_string'));
                if (in_array('bitbucket', $products, true) && in_array('egress', $directions, true)) {
                    $candidates[] = $item['cidr'] ?? null;
                }
            }
        }

        $cidrs = [];
        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || ! self::isAddress($candidate)) {
                throw new RuntimeException('the response carried an entry that is not an address: '.json_encode($candidate));
            }
            $cidrs[] = trim($candidate);
        }

        if ($cidrs === []) {
            throw new RuntimeException('the response carried no hook ranges');
        }

        return array_values(array_unique($cidrs));
    }

    /**
     * @param  array<string, array<string, mixed>>  $stored
     * @param  array<string, mixed>  $entry
     */
    protected function store(array $stored, string $keyword, array $entry): void
    {
        $stored[$keyword] = $entry;
        $this->settings->set(self::SETTING, $stored);
    }
}
