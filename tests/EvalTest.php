<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Eval_;

// The no-unit evaluation rule is a cross-SDK contract: a request with no unit id
// answers a fully-rolled gate as on (no bucketing needed) but a fractional gate
// as off. See experiment-platform/18-identity-bucketing.md.
final class EvalTest extends TestCase
{
    public function testNoUnitFullRolloutOn(): void
    {
        $this->assertTrue(Eval_::evalGate(['enabled' => 1, 'salt' => 's', 'rolloutPct' => 10000], []));
    }

    public function testNoUnitFractionalOff(): void
    {
        $this->assertFalse(Eval_::evalGate(['enabled' => 1, 'salt' => 's', 'rolloutPct' => 5000], []));
    }

    public function testNoUnitDisabledOrKilledOff(): void
    {
        $this->assertFalse(Eval_::evalGate(['enabled' => 0, 'rolloutPct' => 10000], []));
        $this->assertFalse(Eval_::evalGate(['enabled' => 1, 'killswitch' => 1, 'rolloutPct' => 10000], []));
    }

    public function testNoUnitTargetingRuleWins(): void
    {
        $gate = [
            'enabled' => 1, 'salt' => 's', 'rolloutPct' => 10000,
            'rules' => [['attr' => 'plan', 'op' => 'eq', 'value' => 'pro']],
        ];
        $this->assertFalse(Eval_::evalGate($gate, []));
        $this->assertTrue(Eval_::evalGate($gate, ['plan' => 'pro']));
    }

    public function testWithUnitUnchanged(): void
    {
        $this->assertFalse(Eval_::evalGate(['enabled' => 1, 'salt' => 's', 'rolloutPct' => 0], ['user_id' => 'u1']));
        $this->assertTrue(Eval_::evalGate(['enabled' => 1, 'salt' => 's', 'rolloutPct' => 10000], ['user_id' => 'u1']));
    }
}
