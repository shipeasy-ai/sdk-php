<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Admin\AdminClient;

/**
 * Exercises the optional Admin API client. The generated client needs
 * `guzzlehttp/guzzle`, which the base SDK does not require — when it is absent
 * every test self-skips so the suite stays green and the gap is reported as
 * UNVERIFIED. Constructing the client touches no network.
 */
final class AdminClientTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\GuzzleHttp\Client::class)) {
            $this->markTestSkipped('guzzlehttp/guzzle not installed — admin client is UNVERIFIED.');
        }
    }

    public function testConstructsAndWiresAuthAndHost(): void
    {
        $admin = new AdminClient('sdk_admin_test', 'proj_123', 'http://localhost:3000');
        $config = $admin->configuration();
        $this->assertSame('sdk_admin_test', $config->getAccessToken());
        $this->assertSame('http://localhost:3000', $config->getHost());
    }

    public function testExposesResourceGroups(): void
    {
        $admin = new AdminClient('sdk_admin_test', 'proj_123');
        $this->assertInstanceOf(\Shipeasy\Admin\Generated\Api\GatesApi::class, $admin->gates());
        $this->assertInstanceOf(\Shipeasy\Admin\Generated\Api\ExperimentsApi::class, $admin->experiments());
        // Lazily constructed but cached: same instance on repeat access.
        $this->assertSame($admin->gates(), $admin->gates());
    }

    public function testDefaultHostIsProduction(): void
    {
        $admin = new AdminClient('sdk_admin_test');
        $this->assertSame('https://shipeasy.ai', $admin->configuration()->getHost());
    }
}
