<?php

namespace Slipstream\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Slipstream\Models\Deployment;
use Slipstream\Settings\SettingsRepository;
use Slipstream\Slipstream;

/**
 * Fire-and-forget deploy outcome notifications (mail / Slack / Discord / generic webhook).
 */
class DeployNotifier
{
    public function __construct(
        protected SettingsRepository $settings
    ) {}

    public function deploymentFinished(Deployment $deployment): void
    {
        // Superseded triggers are covered by the in-progress deploy's catch-up —
        // never a real outcome, so never notify.
        if ($deployment->status === Deployment::STATUS_SUPERSEDED) {
            return;
        }

        $success = $deployment->status === Deployment::STATUS_DEPLOYED
            || $deployment->status === Deployment::STATUS_ROLLED_BACK;

        $notifySuccess = filter_var($this->settings->get('notify_on_success', true), FILTER_VALIDATE_BOOLEAN);
        $notifyFailure = filter_var($this->settings->get('notify_on_failure', true), FILTER_VALIDATE_BOOLEAN);

        if ($success && ! $notifySuccess) {
            return;
        }
        if (! $success && ! $notifyFailure) {
            return;
        }

        // Skip noisy coalesce rows.
        if (str_contains((string) $deployment->failure_reason, 'Coalesced:')) {
            return;
        }

        $title = $success
            ? ($deployment->status === Deployment::STATUS_ROLLED_BACK ? 'Rollback complete' : 'Deploy succeeded')
            : 'Deploy failed';

        $lines = [
            $title,
            'Server: '.$deployment->server_name,
            'Status: '.$deployment->status,
            'SHA: '.($deployment->shortSha() ?: '—'),
            'Branch: '.($deployment->branch ?: '—'),
            'Trigger: '.$deployment->trigger,
        ];

        if ($deployment->duration_ms) {
            $lines[] = 'Duration: '.number_format($deployment->duration_ms).'ms';
        }
        if ($deployment->commit_message) {
            $lines[] = 'Commit: '.$deployment->commit_message;
        }
        if ($deployment->failure_reason) {
            $lines[] = 'Reason: '.$deployment->failure_reason;
        }

        $text = implode("\n", $lines);
        $payload = [
            'text' => $text,
            'title' => $title,
            'success' => $success,
            'deployment' => [
                'id' => $deployment->id,
                'server_name' => $deployment->server_name,
                'status' => $deployment->status,
                'sha' => $deployment->sha,
                'branch' => $deployment->branch,
                'trigger' => $deployment->trigger,
                'duration_ms' => $deployment->duration_ms,
                'failure_reason' => $deployment->failure_reason,
            ],
            'app' => config('app.name'),
            'node' => Slipstream::serverName(),
        ];

        $this->sendMail($title, $text, $success);
        $this->sendSlack($text, $success);
        $this->sendDiscord($text, $success);
        $this->sendGenericWebhook($payload);
    }

    protected function sendMail(string $subject, string $body, bool $success): void
    {
        $to = $this->settings->get('notification_mail');
        if (! is_string($to) || $to === '') {
            return;
        }

        try {
            Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject('[Slipstream] '.$subject);
            });
        } catch (\Throwable $e) {
            Log::warning('Slipstream mail notification failed: '.$this->redact($e->getMessage(), $to));
        }
    }

    protected function sendSlack(string $text, bool $success): void
    {
        $url = $this->settings->get('notification_slack_url');
        if (! is_string($url) || $url === '') {
            return;
        }

        try {
            Http::timeout(5)->post($url, [
                'text' => $text,
                'username' => 'Slipstream',
                'icon_emoji' => $success ? ':white_check_mark:' : ':x:',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Slipstream Slack notification failed: '.$this->redact($e->getMessage(), $url));
        }
    }

    protected function sendDiscord(string $text, bool $success): void
    {
        $url = $this->settings->get('notification_discord_url');
        if (! is_string($url) || $url === '') {
            return;
        }

        try {
            Http::timeout(5)->post($url, [
                'content' => ($success ? '✅' : '❌')." **Slipstream**\n```\n{$text}\n```",
            ]);
        } catch (\Throwable $e) {
            Log::warning('Slipstream Discord notification failed: '.$this->redact($e->getMessage(), $url));
        }
    }

    protected function sendGenericWebhook(array $payload): void
    {
        $url = $this->settings->get('notification_webhook_url');
        if (! is_string($url) || $url === '') {
            return;
        }

        try {
            Http::timeout(5)->asJson()->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('Slipstream webhook notification failed: '.$this->redact($e->getMessage(), $url));
        }
    }

    /**
     * Keep the (encrypted-at-rest) destination out of laravel.log — cURL/Guzzle
     * exception messages embed the full URL, which would defeat the encryption.
     */
    protected function redact(string $message, ?string $secretValue): string
    {
        if (is_string($secretValue) && $secretValue !== '') {
            $message = str_replace($secretValue, '[redacted]', $message);
        }

        return $message;
    }
}
