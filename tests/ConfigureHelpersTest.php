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
        ]);
        $c = new Client(['user_id' => 'u_1']);
        $this->assertTrue($c->getFlag('new_checkout'));
        $this->assertSame('blue', $c->getConfig('theme'));

        // REPLACE (not first-wins): a second call wins.
        configureForTesting(['flags' => ['new_checkout' => false]]);
        $this->assertFalse((new Client([]))->getFlag('new_checkout'));
    }

    public function testExperimentOverrideSurfacesThroughAssign(): void
    {
        // Overrides refine an experiment that lives in a universe — they don't
        // invent one in an empty universe. Seed a real (offline) experiment in
        // universe `pricing`, then force the enrolment with overrideExperiment.
        configureForOffline([
            'snapshot' => [
                'flags' => ['gates' => [], 'configs' => [], 'killswitches' => []],
                'experiments' => [
                    'universes' => ['pricing' => ['holdout_range' => null]],
                    'experiments' => [
                        'price_test' => [
                            'universe' => 'pricing',
                            'allocationPct' => 10000,
                            'salt' => 's',
                            'status' => 'running',
                            'groups' => [['name' => 'control', 'weight' => 10000, 'params' => ['price' => 0]]],
                        ],
                    ],
                ],
            ],
            'experiments' => ['price_test' => ['treatment', ['price' => 9]]],
        ]);
        $a = (new Client(['user_id' => 'u_1']))->universe('pricing')->assign();
        $this->assertTrue($a->enrolled());
        $this->assertSame('treatment', $a->group);
        $this->assertSame(9, $a->get('price'));
    }

    public function testPackageOverridesAndClear(): void
    {
        // Seed a real experiment `e` in universe `u` so the experiment override
        // is reachable via universe()->assign(); flags/configs ride the blob.
        configureForOffline([
            'snapshot' => [
                'flags' => ['gates' => [], 'configs' => [], 'killswitches' => []],
                'experiments' => [
                    'universes' => ['u' => ['holdout_range' => null]],
                    'experiments' => [
                        'e' => [
                            'universe' => 'u',
                            'allocationPct' => 10000,
                            'salt' => 's',
                            'status' => 'running',
                            'groups' => [['name' => 'A', 'weight' => 10000, 'params' => ['v' => 1]]],
                        ],
                    ],
                ],
            ],
            'flags' => ['f' => true],
        ]);
        overrideFlag('f', false);
        overrideConfig('c', 123);
        overrideExperiment('e', 'B', ['v' => 2]);
        $c = new Client(['user_id' => 'u']);
        $this->assertFalse($c->getFlag('f'));
        $this->assertSame(123, $c->getConfig('c'));
        $this->assertSame('B', $c->universe('u')->assign()->group);

        // clearOverrides drops the override layer; the experiment reverts to its
        // real assignment (group A).
        clearOverrides();
        $this->assertNull((new Client([]))->getConfig('c'));
        $this->assertSame('A', (new Client(['user_id' => 'u']))->universe('u')->assign()->group);
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
