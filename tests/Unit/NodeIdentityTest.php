<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Fleet\NodeIdentity;
use Slipstream\Slipstream;
use Slipstream\Tests\TestCase;

/**
 * Poll-mode node names: unique per machine, because EC2 hostnames follow
 * private IPs, and IPs are reused.
 */
class NodeIdentityTest extends TestCase
{
    protected string $sys;

    protected string $machineId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sys = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-sys-'.bin2hex(random_bytes(4));
        mkdir($this->sys, 0777, true);
        $this->machineId = $this->sys.DIRECTORY_SEPARATOR.'machine-id';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->sys);

        parent::tearDown();
    }

    public function test_a_nitro_instance_is_named_after_its_instance_id_without_asking_the_network(): void
    {
        $this->sysFile('devices/virtual/dmi/id/board_asset_tag', "i-0af01c0123456789a\n");

        $identity = new NodeIdentity($this->sys, $this->machineId, fn () => $this->fail('no IMDS call needed'));

        $this->assertSame('i-0af01c0123456789a', $identity->name('ip-10-0-1-23'));
    }

    public function test_a_xen_instance_asks_imds_for_its_id(): void
    {
        $this->sysFile('hypervisor/uuid', "ec2e1916-9099-7caf-fd21-012345abcdef\n");

        $this->assertSame('i-0123456789abcdef0', (new NodeIdentity($this->sys, $this->machineId, fn () => 'i-0123456789abcdef0'))->name('ip-10-0-1-23'));

        // A BIOS that says Amazon is recognised too; a junk answer is ignored.
        unlink($this->sys.'/hypervisor/uuid');
        $this->sysFile('devices/virtual/dmi/id/bios_version', '4.11.amazon');
        $this->assertSame('ip-10-0-1-23', (new NodeIdentity($this->sys, $this->machineId, fn () => '<html>'))->name('ip-10-0-1-23'));
    }

    public function test_off_ec2_it_is_the_hostname_and_the_machine_id_and_never_the_network(): void
    {
        $this->sysFile('devices/virtual/dmi/id/board_asset_tag', 'Not Specified');
        file_put_contents($this->machineId, "3f2a9c1b8e7d4a6f9b0c1d2e3f4a5b6c\n");

        $identity = new NodeIdentity($this->sys, $this->machineId, fn () => $this->fail('no IMDS call off EC2'));

        $this->assertSame('web-3f2a9c1b', $identity->name('web'));

        unlink($this->machineId);
        $this->assertSame('web', $identity->name('web'));
    }

    public function test_only_poll_mode_without_slipstream_server_derives_a_name(): void
    {
        $probes = 0;
        $this->app->instance(NodeIdentity::class, new class($probes) extends NodeIdentity
        {
            public function __construct(private int &$probes) {}

            public function name(string $hostname): string
            {
                $this->probes++;

                return 'i-0af01c0123456789a';
            }
        });

        $this->assertSame('test-node', Slipstream::serverName(), 'SLIPSTREAM_SERVER wins');

        config()->set('slipstream.server_name', null);
        $this->assertSame(gethostname(), Slipstream::serverName(), 'webhook mode keeps the hostname');

        config()->set('slipstream.trigger.mode', 'poll');
        $this->assertSame('i-0af01c0123456789a', Slipstream::serverName());
        $this->assertSame('i-0af01c0123456789a', Slipstream::serverName());
        $this->assertSame(1, $probes, 'worked out once per process');
    }

    protected function sysFile(string $path, string $contents): void
    {
        $file = $this->sys.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $contents);
    }
}
