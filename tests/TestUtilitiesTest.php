<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Assignment;
use Shipeasy\Engine;

final class TestUtilitiesTest extends TestCase
{
    /**
     * A one-experiment universe blob so `universe('u')->assign()` has a running
     * experiment `$name` to land the override on (an override surfaces through
     * assign() only when the experiment exists in the loaded blob).
     */
    private function seedExp(Engine $c, string $name = 'checkout_button', string $universe = 'u'): void
    {
        $c->applyData(
            ['gates' => [], 'configs' => []],
            [
                'universes' => [$universe => ['holdout_range' => null]],
                'experiments' => [
                    $name => [
                        'universe' => $universe,
                        'status' => 'running',
                        'salt' => 'seedsalt1234',
                        'allocationPct' => 10000,
                        'groups' => [['name' => 'control', 'weight' => 10000, 'params' => ['color' => 'blue']]],
                    ],
                ],
            ],
        );
    }

    // forTesting() builds a usable client with no key and never touches the
    // network: init()/initOnce() are no-ops, so unseeded reads return defaults
    // (no RuntimeException from a failed fetch).
    public function testForTestingNeedsNoNetworkOrKey(): void
    {
        $c = Engine::forTesting();
        $c->init();      // no-op, must not fetch/throw
        $c->initOnce();  // no-op, must not fetch/throw

        $this->assertFalse($c->getFlag('unset', ['user_id' => 'u1']));
        $this->assertNull($c->getConfig('unset'));

        // No blob → not enrolled anywhere; assign is still safe and get() falls
        // back to the caller's fallback.
        $a = $c->universe('unset')->assign(['user_id' => 'u1']);
        $this->assertInstanceOf(Assignment::class, $a);
        $this->assertFalse($a->enrolled());
        $this->assertNull($a->group);
        $this->assertSame('blue', $a->get('color', 'blue'));
    }

    public function testOverrideFlagWins(): void
    {
        $c = Engine::forTesting();
        $c->overrideFlag('new_checkout', true);
        $this->assertTrue($c->getFlag('new_checkout', ['user_id' => 'u1']));

        $c->overrideFlag('new_checkout', false);
        $this->assertFalse($c->getFlag('new_checkout', ['user_id' => 'u1']));
    }

    public function testOverrideConfigWins(): void
    {
        $c = Engine::forTesting();
        $c->overrideConfig('billing_copy', ['headline' => 'Hi']);
        $this->assertSame(['headline' => 'Hi'], $c->getConfig('billing_copy'));
    }

    public function testOverrideExperimentSurfacesThroughAssign(): void
    {
        $c = Engine::forTesting();
        $this->seedExp($c);
        $c->overrideExperiment('checkout_button', 'treatment', ['color' => 'green']);

        $a = $c->universe('u')->assign(['user_id' => 'u1']);
        $this->assertTrue($a->enrolled());
        $this->assertSame('checkout_button', $a->name);
        $this->assertSame('treatment', $a->group);
        $this->assertSame('green', $a->get('color'));
    }

    public function testClearOverridesResets(): void
    {
        $c = Engine::forTesting();
        $this->seedExp($c, 'exp');
        $c->overrideFlag('f', true);
        $c->overrideConfig('cfg', 'x');
        $c->overrideExperiment('exp', 'treatment', ['k' => 1]);

        $c->clearOverrides();

        $this->assertFalse($c->getFlag('f', ['user_id' => 'u1']));
        $this->assertNull($c->getConfig('cfg'));
        // Override gone → the unit is bucketed normally into the seeded control.
        $a = $c->universe('u')->assign(['user_id' => 'u1']);
        $this->assertSame('control', $a->group);
        $this->assertSame('blue', $a->get('color'));
    }

    public function testTrackIsNoOpWithoutError(): void
    {
        $c = Engine::forTesting();
        $c->track('u1', 'purchase', ['amount' => 49]);
        $this->addToAssertionCount(1); // reached here = no network/exception
    }

    // Overrides also work on a normal (non-test) client, taking precedence
    // before the bucketing eval — so an overridden experiment surfaces its group.
    public function testOverridesWorkOnNormalClient(): void
    {
        // A non-local engine that swallows /collect POSTs (auto-exposure) so the
        // hermetic suite never touches the network.
        $c = new class ('test-key', null, 'prod', true) extends Engine {
            protected function postNonBlocking(string $path, string $body): void {}
        };
        $this->seedExp($c, 'exp');
        $c->overrideFlag('f', true);
        $c->overrideConfig('cfg', 42);
        $c->overrideExperiment('exp', 'control', ['v' => 1]);

        $this->assertTrue($c->getFlag('f', ['user_id' => 'u1']));
        $this->assertSame(42, $c->getConfig('cfg'));
        $a = $c->universe('u')->assign(['user_id' => 'u1']);
        $this->assertTrue($a->enrolled());
        $this->assertSame('exp', $a->name);
        $this->assertSame('control', $a->group);
        $this->assertSame(1, $a->get('v'));
    }
}
