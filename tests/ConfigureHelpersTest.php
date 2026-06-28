<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Client;
use Shipeasy\Engine;

use function Shipeasy\configureForTesting;
use function Shipeasy\configureForOffline;
use function Shipeasy\overrideFlag;
use function Shipeasy\overrideConfig;
use function Shipeasy\overrideExperiment;
use function Shipeasy\clearOverrides;

/**
 * Doc-23 configure() family + package-level helpers, all read through the bound
 * Client (never the Engine).
 */
final class ConfigureHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        Engine::resetForTesting();
    }

    protected function tearDown(): void
    {
        Engine::resetForTesting();
    }

    public function testConfigureForTestingSeedsAndReplaces(): void
    {
        configureForTesting([
            'flags' => ['new_checkout' => true],
            'configs' => ['theme' => 'blue'],
            'experiments' => ['price_test' => ['treatment', ['price' => 9]]],
        ]);
        $c = new Client(['user_id' => 'u_1']);
        $this->assertTrue($c->getFlag('new_checkout'));
        $this->assertSame('blue', $c->getConfig('theme'));
        $exp = $c->getExperiment('price_test', ['price' => 0]);
        $this->assertTrue($exp->inExperiment);
        $this->assertSame('treatment', $exp->group);

        // REPLACE (not first-wins): a second call wins.
        configureForTesting(['flags' => ['new_checkout' => false]]);
        $this->assertFalse((new Client([]))->getFlag('new_checkout'));
    }

    public function testPackageOverridesAndClear(): void
    {
        configureForTesting(['flags' => ['f' => true]]);
        overrideFlag('f', false);
        overrideConfig('c', 123);
        overrideExperiment('e', 'B', ['v' => 2]);
        $c = new Client(['user_id' => 'u']);
        $this->assertFalse($c->getFlag('f'));
        $this->assertSame(123, $c->getConfig('c'));
        $this->assertSame('B', $c->getExperiment('e', [])->group);

        // Test mode has no blob beneath: clearOverrides drops the seed too.
        clearOverrides();
        $this->assertNull((new Client([]))->getConfig('c'));
    }

    public function testOverrideBeforeConfigureThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        overrideFlag('f', true);
    }

    public function testConfigureForOfflineLayersOverrides(): void
    {
        $snapshot = [
            'flags' => [
                'gates' => [
                    'on_for_all' => ['enabled' => true, 'rules' => [], 'rolloutPct' => 10000, 'salt' => 's'],
                ],
                'configs' => ['color' => ['value' => 'green']],
                'killswitches' => [],
            ],
            'experiments' => ['experiments' => [], 'universes' => []],
        ];
        configureForOffline(['snapshot' => $snapshot]);
        $this->assertTrue((new Client(['user_id' => 'u_1']))->getFlag('on_for_all'));
        $this->assertSame('green', (new Client([]))->getConfig('color'));

        overrideFlag('on_for_all', false);
        $this->assertFalse((new Client([]))->getFlag('on_for_all'));
        clearOverrides();
        $this->assertTrue((new Client([]))->getFlag('on_for_all'));
    }

    public function testConfigureForOfflineRequiresSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        configureForOffline([]);
    }
}
