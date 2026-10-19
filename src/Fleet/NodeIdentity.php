<?php

namespace Slipstream\Fleet;

use Closure;

/**
 * A name no other machine has, for poll mode when SLIPSTREAM_SERVER is unset.
 * A hostname is not one: EC2 derives it from the private IP, and IPs are
 * reused, so a new instance would inherit an old one's registry row (its
 * pause, its pin) and its scheduler mutex.
 *
 * In order: the EC2 instance ID, read from DMI on Nitro instances or asked of
 * IMDSv2 on Xen ones, both recognised from local files first, so a machine
 * that is not EC2 never waits on the network; else the hostname with the
 * start of /etc/machine-id; else the hostname.
 */
class NodeIdentity
{
    /**
     * @param  (Closure(): ?string)|null  $imds  the instance ID from IMDSv2; the real service when null
     */
    public function __construct(
        protected string $sys = '/sys',
        protected string $machineIdFile = '/etc/machine-id',
        protected ?Closure $imds = null,
    ) {}

    public function name(string $hostname): string
    {
        if (($instance = $this->ec2InstanceId()) !== null) {
            return $instance;
        }

        $machine = $this->read($this->machineIdFile);
        if ($machine !== null && preg_match('/^[0-9a-f]{32}$/', $machine) === 1) {
            return $hostname.'-'.substr($machine, 0, 8);
        }

        return $hostname;
    }

    public function ec2InstanceId(): ?string
    {
        // Nitro: the board's asset tag is the instance ID, readable by anyone.
        $tag = $this->read($this->sys.'/devices/virtual/dmi/id/board_asset_tag');
        if ($tag !== null && self::isInstanceId($tag)) {
            return $tag;
        }

        // Xen: the hypervisor UUID (or the BIOS version) says EC2, and only
        // the metadata service knows the ID.
        $uuid = $this->read($this->sys.'/hypervisor/uuid');
        $bios = $this->read($this->sys.'/devices/virtual/dmi/id/bios_version');
        if (! (($uuid !== null && str_starts_with($uuid, 'ec2')) || ($bios !== null && str_contains($bios, 'amazon')))) {
            return null;
        }

        $id = $this->imds !== null ? ($this->imds)() : $this->askImds();
        $id = is_string($id) ? strtolower(trim($id)) : null;

        return $id !== null && self::isInstanceId($id) ? $id : null;
    }

    public static function isInstanceId(string $value): bool
    {
        return preg_match('/^i-[0-9a-f]{8}(?:[0-9a-f]{9})?$/', $value) === 1;
    }

    protected function askImds(): ?string
    {
        $base = 'http://169.254.169.254/latest';

        $token = $this->http('PUT', $base.'/api/token', 'X-aws-ec2-metadata-token-ttl-seconds: 60');

        return $token === null ? null : $this->http('GET', $base.'/meta-data/instance-id', 'X-aws-ec2-metadata-token: '.$token);
    }

    protected function http(string $method, string $url, string $header): ?string
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $header,
            'timeout' => 1,
        ]]);

        $body = @file_get_contents($url, false, $context);

        return is_string($body) && trim($body) !== '' ? trim($body) : null;
    }

    protected function read(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $value = @file_get_contents($path);

        return is_string($value) && trim($value) !== '' ? strtolower(trim($value)) : null;
    }
}
