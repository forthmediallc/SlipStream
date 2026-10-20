<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Slipstream\Console\Commands\Concerns\FormatsConsoleOutput;
use Slipstream\Release\ReleaseVerifier;
use Slipstream\Slipstream;
use Slipstream\Support\AuditLog;

/**
 * Compare a release on disk with its signed manifest. Scheduled weekly in
 * artifact mode, it is the change-detection check PCI DSS 11.5.2 asks for
 * on the code a CDE node serves: every file CI signed is still there and
 * unchanged, and nothing was added. What the node writes itself
 * (bootstrap/cache, the .env and storage links, the shared paths) is left
 * out.
 */
class VerifyCommand extends Command
{
    use FormatsConsoleOutput;

    public const NOTHING_TO_VERIFY = 2;

    protected $signature = 'slipstream:verify
                            {--release= : A release directory (default: the live release, current/)}
                            {--json : Machine-readable output}
                            {--audit : Record the outcome in the audit log}';

    protected $description = 'Compare a release with its signed manifest — change detection for the live release (PCI DSS 11.5.2)';

    public function handle(ReleaseVerifier $verifier): int
    {
        $release = (string) ($this->option('release') ?: Slipstream::appRoot().DIRECTORY_SEPARATOR.'current');
        $release = realpath($release) ?: $release;

        if (! is_dir($release)) {
            $this->components->error("No release at {$release}.");

            return self::NOTHING_TO_VERIFY;
        }

        $result = $verifier->verify($release);

        if (! $result['manifest']) {
            $reason = $result['signature_error'] ?? 'it carries no .slipstream/manifest.json';

            // In git mode a release has no manifest to compare. In artifact
            // mode every release has one, so a missing or unreadable one is
            // drift, never silence: deleting it must not stop the check.
            if (! Slipstream::artifactMode()) {
                if ($this->option('json')) {
                    $this->json($result);
                } else {
                    $this->components->warn("Nothing to verify: {$release} carries no .slipstream/manifest.json — a release deployed from git has none.");
                }

                return self::NOTHING_TO_VERIFY;
            }

            if ($this->option('audit')) {
                AuditLog::record('artifact.verify_drift', ['release' => $release, 'sha' => null, 'reason' => 'no usable manifest: '.$reason]);
            }
            if ($this->option('json')) {
                $this->json($result + ['reason' => 'no usable manifest: '.$reason]);
            } else {
                $this->components->error("Cannot verify {$release}: {$reason}. In artifact mode every release carries its signed manifest; redeploy it (php artisan slipstream:deploy) and find out what removed it.");
            }

            return self::FAILURE;
        }

        $drift = count($result['modified']) + count($result['missing']) + count($result['unlisted']) + count($result['links']) + count($result['generated']);

        if ($this->option('audit')) {
            AuditLog::record($result['ok'] ? 'artifact.verify_ok' : 'artifact.verify_drift', [
                'release' => $release,
                'sha' => $result['sha'],
                'files' => $result['files'],
                'signed' => $result['signed'],
                'signature_error' => $result['signature_error'],
                'modified' => count($result['modified']),
                'missing' => count($result['missing']),
                'unlisted' => count($result['unlisted']),
                'links' => count($result['links']),
                'generated' => count($result['generated']),
                'baseline' => $result['baseline'],
                'paths' => array_slice(array_merge($result['modified'], $result['missing'], $result['unlisted'], $result['links'], $result['generated']), 0, 20),
            ]);
        }

        if ($this->option('json')) {
            $this->json($result);

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('Release', $this->shortSha($result['sha']).' · '.$release);
        $this->components->twoColumnDetail('Signature', $result['signed'] ? 'verifies with key '.$result['key_id'] : '<fg=red>does not verify: '.$result['signature_error'].'</>');
        $this->components->twoColumnDetail('Files checked', (string) $result['files']);
        if ($result['release_sha'] !== null && $result['release_sha'] !== $result['sha']) {
            $this->components->twoColumnDetail('RELEASE_SHA', '<fg=red>'.$result['release_sha'].', not the manifest\'s</>');
        }

        $this->components->twoColumnDetail('Generated caches', $result['baseline']
            ? ($result['generated'] === [] ? 'match the hashes recorded at deploy' : '<fg=red>changed since the deploy</>')
            : 'no hashes recorded (deployed before 0.6.1)');

        foreach (['modified' => 'Modified', 'missing' => 'Missing', 'unlisted' => 'Not in the manifest', 'links' => 'Symlinks', 'generated' => 'Generated since the deploy'] as $kind => $label) {
            if ($result[$kind] === []) {
                continue;
            }
            $this->components->twoColumnDetail($label, '<fg=red>'.count($result[$kind]).'</>');
            foreach (array_slice($result[$kind], 0, 50) as $path) {
                $this->line('    '.$path);
            }
            if (count($result[$kind]) > 50) {
                $this->line('    … and '.(count($result[$kind]) - 50).' more');
            }
        }

        if ($result['ok']) {
            $this->components->info('The release matches its signed manifest.');

            return self::SUCCESS;
        }

        $this->components->error($drift > 0
            ? "{$drift} path(s) differ from the signed manifest. A release that changed under its manifest is not the release CI signed."
            : 'The release does not verify: '.$result['signature_error']);

        return self::FAILURE;
    }
}
