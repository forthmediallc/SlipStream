<?php

namespace Slipstream\Settings;

use Illuminate\Support\Facades\Crypt;
use Slipstream\Models\Setting;
use Slipstream\Slipstream;

class SettingsRepository
{
    /** @var array<string, mixed>|null */
    protected ?array $cache = null;

    /**
     * Keys stored encrypted at rest.
     *
     * @var list<string>
     */
    public const ENCRYPTED_KEYS = [
        'webhook_secret',
        'peer_secret',
        'repo_url',
        'notification_webhook_url',
        'notification_slack_url',
        'notification_discord_url',
    ];

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if (! $this->tableReady()) {
            return $this->cache = [];
        }

        $rows = Setting::query()->get();
        $out = [];

        foreach ($rows as $row) {
            $value = $row->value;
            if ($row->encrypted && is_string($value) && $value !== '') {
                try {
                    $value = Crypt::decryptString($value);
                } catch (\Throwable) {
                    // leave ciphertext if APP_KEY rotated — surface empty
                    $value = null;
                }
            }

            if (is_string($value) && $this->looksLikeJson($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                }
            }

            $out[$row->key] = $value;
        }

        return $this->cache = $out;
    }

    public function set(string $key, mixed $value, ?bool $encrypted = null): void
    {
        $encrypted ??= in_array($key, self::ENCRYPTED_KEYS, true);

        if ($value === null || $value === '') {
            $this->forget($key);

            return;
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_THROW_ON_ERROR);
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } else {
            $value = (string) $value;
        }

        $store = $value;
        if ($encrypted && is_string($store) && $store !== '') {
            $store = Crypt::encryptString($store);
        }

        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $store, 'encrypted' => $encrypted]
        );

        $this->cache = null;
    }

    public function forget(string $key): void
    {
        Setting::query()->where('key', $key)->delete();
        $this->cache = null;
    }

    public function webhookSecret(): ?string
    {
        $secret = $this->get('webhook_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    public function ensureWebhookSecret(): string
    {
        $existing = $this->webhookSecret();
        if ($existing) {
            return $existing;
        }

        $secret = bin2hex(random_bytes(32));
        $this->set('webhook_secret', $secret, encrypted: true);

        return $secret;
    }

    public function rotateWebhookSecret(): string
    {
        $secret = bin2hex(random_bytes(32));
        $this->set('webhook_secret', $secret, encrypted: true);

        return $secret;
    }

    /**
     * The key that signs and verifies server-to-server traffic: relay fan-out
     * and env-sync nudges.
     *
     * Deliberately NOT the webhook secret. That value is handed to GitHub /
     * GitLab / Bitbucket (GitLab even transmits it verbatim in every
     * X-Gitlab-Token header), so anyone who obtains it there — a git-host admin,
     * a header-logging proxy, a leaked CI variable — would otherwise be able to
     * forge a relay push and deploy any commit in the mirror on every node.
     *
     * Installs that predate the split have no peer_secret; they fall back to the
     * webhook secret so a fleet mid-upgrade keeps verifying. Separating them is
     * an explicit operator action (dashboard → Webhook → fleet key), because the
     * moment a peer secret exists every node must be running code that knows to
     * sign with it.
     */
    public function peerSecret(): ?string
    {
        $secret = $this->get('peer_secret');
        if (is_string($secret) && $secret !== '') {
            return $secret;
        }

        return $this->webhookSecret();
    }

    /**
     * Whether the fleet key is actually its own secret rather than the legacy
     * fallback to the provider-shared webhook secret.
     */
    public function hasSeparatePeerSecret(): bool
    {
        $secret = $this->get('peer_secret');

        return is_string($secret) && $secret !== '';
    }

    public function ensurePeerSecret(): string
    {
        if ($this->hasSeparatePeerSecret()) {
            return (string) $this->get('peer_secret');
        }

        return $this->rotatePeerSecret();
    }

    public function rotatePeerSecret(): string
    {
        $secret = bin2hex(random_bytes(32));
        $this->set('peer_secret', $secret, encrypted: true);

        return $secret;
    }

    public function branch(): string
    {
        return (string) ($this->get('branch') ?: config('slipstream.deploy.branch', 'main'));
    }

    public function keepReleases(): int
    {
        return (int) ($this->get('keep_releases') ?: config('slipstream.deploy.keep_releases', 5));
    }

    public function repoUrl(): ?string
    {
        $url = $this->get('repo_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @return array<string, string>
     */
    public function sharedPaths(): array
    {
        $fromDb = $this->get('shared_paths');
        if (is_array($fromDb) && $fromDb !== []) {
            return $fromDb;
        }

        return (array) config('slipstream.deploy.shared_paths', []);
    }

    protected function tableReady(): bool
    {
        try {
            return Slipstream::schema()->hasTable('slipstream_settings');
        } catch (\Throwable) {
            return false;
        }
    }

    protected function looksLikeJson(string $value): bool
    {
        $value = trim($value);

        return ($value !== '' && ($value[0] === '{' || $value[0] === '['));
    }
}
