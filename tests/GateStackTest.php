<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Eval_;

// Gatekeeper `stack` evaluation in the PHP SDK.
//
// Regression guard for the bug where Eval_::evalGate read only the flat
// `rules`+`rolloutPct` columns and ignored a modern gate's ordered `stack`. The
// canonical model is the stack (mirrors @shipeasy/core evalGatekeeper + the edge
// worker); the flat columns are a lossy approximation that can invert the result
// (a whitelist condition at 100% followed by a 0% public rollout flattens to
// `rolloutPct: 0`). These vectors lock the SDK to the stack.
final class GateStackTest extends TestCase
{
    private const P = 'e976b15e-3ccc-44d3-821d-87f06d5a0e43';

    /**
     * The exact shape the KV rebuild ships for a whitelist gatekeeper: a
     * condition (no explicit rolloutPct ⇒ 100%) that whitelists a project, then a
     * locked 0% public rollout. The flat columns are the lossy approximation.
     *
     * @return array<string,mixed>
     */
    private static function whitelistGate(): array
    {
        return [
            'name' => 'release_module',
            'enabled' => 1,
            'salt' => 'caf3a1ae',
            // Lossy flat approximation — must NOT be what decides the result.
            'rules' => [['attr' => 'project_id', 'op' => 'in', 'value' => [self::P]]],
            'rolloutPct' => 0,
            'stack' => [
                [
                    'id' => 'gq578snc',
                    'type' => 'condition',
                    'pass' => 'all',
                    'rules' => [['attr' => 'project_id', 'op' => 'in', 'value' => [self::P]]],
                ],
                ['id' => 'gu0uein4', 'type' => 'rollout', 'rolloutPct' => 0, 'bucketBy' => 'user_id', 'salt' => 'public'],
            ],
        ];
    }

    public function testWhitelistedCallerPassesDespiteFlatRolloutZero(): void
    {
        // The regression: the flat path would read "matches whitelist AND 0%
        // bucket" = false. The stack short-circuits on the 100% condition → true.
        $user = ['user_id' => 'cdewqzx@gmail.com', 'project_id' => self::P];
        $this->assertTrue(Eval_::evalGate(self::whitelistGate(), $user));
    }

    public function testNonWhitelistedCallerHidden(): void
    {
        $user = ['user_id' => 'someone@else.com', 'project_id' => 'other-project'];
        $this->assertFalse(Eval_::evalGate(self::whitelistGate(), $user));
    }

    public function testWhitelistedCallerWithNoIdentityPasses(): void
    {
        // No user_id/anonymous_id: a fully-rolled (100%) condition is answerable
        // without a unit id.
        $this->assertTrue(Eval_::evalGate(self::whitelistGate(), ['project_id' => self::P]));
    }

    public function testMatchingConditionStillGatesOnItsOwnRollout(): void
    {
        $gate = [
            'name' => 'g',
            'enabled' => 1,
            'salt' => 's',
            'rules' => [],
            'rolloutPct' => 0,
            'stack' => [
                [
                    'id' => 'c1',
                    'type' => 'condition',
                    'pass' => 'all',
                    'rules' => [['attr' => 'project_id', 'op' => 'in', 'value' => [self::P]]],
                    'rolloutPct' => 0, // matched but 0% → never
                ],
            ],
        ];
        $this->assertFalse(Eval_::evalGate($gate, ['user_id' => 'u1', 'project_id' => self::P]));
    }

    public function testSupportsPassAnyConditions(): void
    {
        $gate = [
            'name' => 'g',
            'enabled' => 1,
            'salt' => 's',
            'rules' => [],
            'rolloutPct' => 0,
            'stack' => [
                [
                    'id' => 'c1',
                    'type' => 'condition',
                    'pass' => 'any',
                    'rules' => [
                        ['attr' => 'plan', 'op' => 'eq', 'value' => 'pro'],
                        ['attr' => 'project_id', 'op' => 'in', 'value' => [self::P]],
                    ],
                ],
            ],
        ];
        // The plan rule misses but the project rule matches → pass.
        $this->assertTrue(Eval_::evalGate($gate, ['user_id' => 'u', 'plan' => 'free', 'project_id' => self::P]));
        $this->assertFalse(Eval_::evalGate($gate, ['user_id' => 'u', 'plan' => 'free', 'project_id' => 'x']));
    }

    public function testFallsThroughToCatchAllRollout(): void
    {
        $gate = [
            'name' => 'g',
            'enabled' => 1,
            'salt' => 's',
            'rules' => [],
            'rolloutPct' => 0,
            'stack' => [
                [
                    'id' => 'c1',
                    'type' => 'condition',
                    'pass' => 'all',
                    'rules' => [['attr' => 'project_id', 'op' => 'in', 'value' => [self::P]]],
                ],
                ['id' => 'public', 'type' => 'rollout', 'rolloutPct' => 10000], // everyone else: 100%
            ],
        ];
        $this->assertTrue(Eval_::evalGate($gate, ['user_id' => 'u', 'project_id' => 'not-whitelisted']));
    }

    public function testDisabledOrKilledStackedGateIsOff(): void
    {
        $base = self::whitelistGate();
        $user = ['user_id' => 'u', 'project_id' => self::P];
        $this->assertFalse(Eval_::evalGate(['enabled' => 0] + $base, $user));
        $this->assertFalse(Eval_::evalGate(['killswitch' => 1] + $base, $user));
    }

    public function testStacklessGateStillUsesLegacyFlatPath(): void
    {
        $on = ['name' => 'on', 'enabled' => 1, 'salt' => 's', 'rules' => [], 'rolloutPct' => 10000];
        $off = ['name' => 'off', 'enabled' => 1, 'salt' => 's', 'rules' => [], 'rolloutPct' => 0];
        $this->assertTrue(Eval_::evalGate($on, ['user_id' => 'u']));
        $this->assertFalse(Eval_::evalGate($off, ['user_id' => 'u']));
    }
}
