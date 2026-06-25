<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Engine;
use Shipeasy\ExperimentResult;

final class TestUtilitiesTest extends TestCase
{
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

        $r = $c->getExperiment('unset', ['user_id' => 'u1'], ['color' => 'blue']);
        $this->assertInstanceOf(ExperimentResult::class, $r);
        $this->assertFalse($r->inExperiment);
        $this->assertSame(['color' => 'blue'], $r->params);
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

    public function testOverrideExperimentWins(): void
    {
        $c = Engine::forTesting();
        $c->overrideExperiment('checkout_button', 'treatment', ['color' => 'green']);

        $r = $c->getExperiment('checkout_button', ['user_id' => 'u1'], ['color' => 'blue']);
        $this->assertTrue($r->inExperiment);
        $this->assertSame('treatment', $r->group);
        $this->assertSame(['color' => 'green'], $r->params);
    }

    public function testClearOverridesResets(): void
    {
        $c = Engine::forTesting();
        $c->overrideFlag('f', true);
        $c->overrideConfig('cfg', 'x');
        $c->overrideExperiment('exp', 'treatment', ['k' => 1]);

        $c->clearOverrides();

        $this->assertFalse($c->getFlag('f', ['user_id' => 'u1']));
        $this->assertNull($c->getConfig('cfg'));
        $r = $c->getExperiment('exp', ['user_id' => 'u1'], ['k' => 0]);
        $this->assertFalse($r->inExperiment);
        $this->assertSame(['k' => 0], $r->params);
    }

    public function testTrackIsNoOpWithoutError(): void
    {
        $c = Engine::forTesting();
        $c->track('u1', 'purchase', ['amount' => 49]);
        $this->addToAssertionCount(1); // reached here = no network/exception
    }

    // Overrides also work on a normal (non-test) client, taking precedence
    // before any telemetry/blob read — so no network occurs for overridden keys.
    public function testOverridesWorkOnNormalClient(): void
    {
        $c = new Engine('test-key', null, 'prod', true);
        $c->overrideFlag('f', true);
        $c->overrideConfig('cfg', 42);
        $c->overrideExperiment('exp', 'control', ['v' => 1]);

        $this->assertTrue($c->getFlag('f', ['user_id' => 'u1']));
        $this->assertSame(42, $c->getConfig('cfg'));
        $r = $c->getExperiment('exp', ['user_id' => 'u1'], ['v' => 0]);
        $this->assertTrue($r->inExperiment);
        $this->assertSame('control', $r->group);
        $this->assertSame(['v' => 1], $r->params);
    }
}
