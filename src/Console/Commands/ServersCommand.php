<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Slipstream\Console\Commands\Concerns\FormatsConsoleOutput;
use Slipstream\Fleet\Drift;
use Slipstream\Fleet\Heartbeat;
use Slipstream\Models\EnvPush;
use Slipstream\Models\EnvSnapshot;
use Slipstream\Models\Server;
use Slipstream\Slipstream;
use Slipstream\Support\Activity;

/**
 * The Servers page from the shell: who is in the fleet and how they are
 * doing, pause/resume and enable/disable, the per-server settings, and
 * forgetting a node that is gone.
 */
class ServersCommand extends Command
{
    use FormatsConsoleOutput;

    protected $signature = 'slipstream:servers
                            {action=list : list | pause | resume | enable | disable | set | forget}
                            {name? : Server name (default: this node)}
                            {--base-url= : Origin peers use to nudge this server, e.g. https://web-2.example.com ("" to clear)}
                            {--fpm-service= : PHP-FPM service reloaded after a swap ("" = config default)}
                            {--horizon-program= : Supervisor program for Horizon ("" = config default)}
                            {--reverb-program= : Supervisor program for Reverb ("" = config default)}
                            {--run-migrations= : 1/0 — whether this server may run migrations}
                            {--force : Forget without asking}
                            {--json : Machine-readable output (list)}';

    protected $description = 'List and manage fleet servers — the Servers page';

    public function handle(Drift $drift, Heartbeat $heartbeat): int
    {
        try {
            return match ($this->argument('action')) {
                'list' => $this->list($drift, $heartbeat),
                'pause' => $this->pause($this->server(), true),
                'resume' => $this->pause($this->server(), false),
                'enable' => $this->enable($this->server(), true),
                'disable' => $this->enable($this->server(), false),
                'set' => $this->set($this->server()),
                'forget' => $this->forget($this->server()),
                default => $this->unknownAction(),
            };
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    protected function list(Drift $drift, Heartbeat $heartbeat): int
    {
        // The page pulses on open so this node is visible at once; same here.
        try {
            $heartbeat->pulse();
        } catch (\Throwable) {
            // A failed pulse must not hide the list.
        }

        $staleAfter = (int) config('slipstream.fleet.heartbeat_stale_seconds', 120);
        $summary = $drift->summary($staleAfter);

        if ($this->option('json')) {
            $this->json([
                'heartbeat_stale_seconds' => $staleAfter,
                'servers' => $summary['servers']->map(fn (Server $s) => $this->serverJson($s, $staleAfter))->values()->all(),
                'drift' => [
                    'has_drift' => $summary['has_drift'],
                    'canonical_sha' => $summary['canonical_sha'],
                    'stale' => $summary['stale']->pluck('server_name')->values()->all(),
                    'offline' => $summary['offline']->pluck('server_name')->values()->all(),
                ],
            ]);

            return self::SUCCESS;
        }

        $this->table($this->serverHeaders, $this->serverRows($summary['servers'], $staleAfter));
        $this->renderDrift($summary);
        $this->line("  Offline after {$staleAfter}s without a heartbeat. Paused = webhooks accepted, builds skipped. Disabled = left out of everything.");

        return self::SUCCESS;
    }

    protected function pause(Server $server, bool $paused): int
    {
        $server->update(['paused' => $paused]);

        Activity::log('server.'.($paused ? 'paused' : 'resumed'), ['server' => $server->server_name, 'via' => 'cli']);
        $this->components->info($paused
            ? "{$server->server_name} paused — webhooks accepted but builds skipped."
            : "{$server->server_name} resumed.");

        return self::SUCCESS;
    }

    protected function enable(Server $server, bool $enabled): int
    {
        $server->update(['enabled' => $enabled]);

        Activity::log('server.'.($enabled ? 'enabled' : 'disabled'), ['server' => $server->server_name, 'via' => 'cli']);
        $this->components->info("{$server->server_name} ".($enabled ? 'enabled' : 'disabled').'.');

        return self::SUCCESS;
    }

    protected function set(Server $server): int
    {
        $changes = [];

        foreach (['base-url' => 'base_url', 'fpm-service' => 'fpm_service', 'horizon-program' => 'horizon_program', 'reverb-program' => 'reverb_program'] as $option => $column) {
            $value = $this->option($option);
            if ($value === null) {
                continue;
            }
            $value = trim((string) $value);
            $changes[$column] = $value === '' ? null : ($column === 'base_url' ? rtrim($value, '/') : $value);
        }

        if (($migrations = $this->flag('run-migrations')) !== null) {
            $changes['run_migrations'] = $migrations;
        }

        if ($changes === []) {
            throw new InvalidArgumentException('Nothing to set. Options: --base-url, --fpm-service, --horizon-program, --reverb-program, --run-migrations.');
        }

        if (array_key_exists('base_url', $changes) && $changes['base_url'] !== null) {
            $validator = Validator::make(['base_url' => $changes['base_url']], ['base_url' => 'url|max:500']);
            if ($validator->fails()) {
                throw new InvalidArgumentException('--base-url must be a full origin such as https://web-2.example.com');
            }
        }

        $server->update($changes);

        Activity::log('server.settings_saved', ['server' => $server->server_name, 'via' => 'cli', 'changed' => array_keys($changes)]);
        foreach ($changes as $column => $value) {
            $this->components->twoColumnDetail($column, is_bool($value) ? ($value ? 'yes' : 'no') : ($value ?? '(config default)'));
        }
        $this->components->info("Settings saved for {$server->server_name}.");

        return self::SUCCESS;
    }

    protected function forget(Server $server): int
    {
        if ($server->server_name === Slipstream::serverName()) {
            throw new InvalidArgumentException('Cannot forget this node from itself.');
        }

        if (! $this->option('force') && ! $this->confirm("Forget {$server->server_name}? Its env snapshot and queued pushes go with it.")) {
            $this->line('Kept.');

            return self::SUCCESS;
        }

        $name = $server->server_name;
        $server->delete();
        EnvSnapshot::query()->where('server_name', $name)->delete();
        EnvPush::query()->where('server_name', $name)->delete();

        Activity::log('server.removed', ['server' => $name, 'via' => 'cli']);
        $this->components->info("Removed {$name} from the fleet registry.");

        return self::SUCCESS;
    }

    protected function server(): Server
    {
        $name = (string) ($this->argument('name') ?: Slipstream::serverName());

        return Server::query()->where('server_name', $name)->first()
            ?? throw new InvalidArgumentException("No server named \"{$name}\" in the registry. See `slipstream:servers list`.");
    }

    protected function unknownAction(): int
    {
        $this->components->error('Unknown action "'.$this->argument('action').'". Use: list, pause, resume, enable, disable, set, forget.');

        return self::FAILURE;
    }
}
