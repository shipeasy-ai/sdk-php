<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Engine;
use Shipeasy\Murmur3;

/**
 * Universe-first assignment (the mutual-exclusion pool model, doc 20 §B).
 *
 * `$engine->universe($name)->assign($user)` returns an Assignment: the ≤1
 * experiment the unit landed in within the universe, its variant, and resolved
 * params (variant override ?? universe default ?? fallback). These specs lock
 * the merge (§B2), the not-enrolled defaults path, pooled mutual exclusion
 * (§B4), reserved headroom (§B5), and the holdout gate (§B3). They seed the
 * blobs directly (no network) via a local (test-mode) engine — mirrors the
 * ts-sdk universe-assign.test.ts.
 */
final class UniverseAssignTest extends TestCase
{
    private const MOD = 10000;

    private static function universeSeg(string $universe, string $uid): int
    {
        return Murmur3::hash32("$universe:$uid") % self::MOD;
    }

    /**
     * A no-network engine seeded with the given flags/experiments blobs.
     *
     * @param array<string, mixed> $flags subset (gates default empty)
     * @param array<string, mixed> $exps  subset (universes/experiments)
     */
    private static function makeEngine(array $flags, array $exps): Engine
    {
        return Engine::fromSnapshot(
            array_merge(['gates' => [], 'configs' => [], 'killswitches' => []], $flags),
            array_merge(['universes' => [], 'experiments' => []], $exps),
        );
    }

    // ---- param merge (§B2) ----

    public function testVariantOverrideWinsUnsetInheritsUnknownFallsBack(): void
    {
        // A universe owns button_color=red, size=1. The one running experiment's
        // assigned variant overrides only button_color.
        $engine = self::makeEngine([], [
            'universes' => [
                'u' => [
                    'holdout_range' => null,
                    'param_schema' => [
                        ['name' => 'button_color', 'type' => 'string', 'default' => 'red'],
                        ['name' => 'size', 'type' => 'int', 'default' => 1],
                    ],
                ],
            ],
            'experiments' => [
                'exp' => [
                    'universe' => 'u',
                    'allocationPct' => 10000,
                    'salt' => 's',
                    'status' => 'running',
                    'groups' => [['name' => 'treatment', 'weight' => 10000, 'params' => ['button_color' => 'blue']]],
                ],
            ],
        ]);

        $a = $engine->universe('u')->assign(['user_id' => 'u1']);
        $this->assertTrue($a->enrolled());
        $this->assertSame('treatment', $a->group);
        // Overridden by the variant.
        $this->assertSame('blue', $a->get('button_color'));
        // Not overridden → inherited from the universe default.
        $this->assertSame(1, $a->get('size'));
        // Absent everywhere → the caller's fallback.
        $this->assertSame('fb', $a->get('missing', 'fb'));
    }

    // ---- not enrolled still gets universe defaults ----

    public function testNotEnrolledResolvesUniverseDefault(): void
    {
        $engine = self::makeEngine([], [
            'universes' => [
                'u' => [
                    'holdout_range' => null,
                    'param_schema' => [['name' => 'button_color', 'type' => 'string', 'default' => 'red']],
                ],
            ],
            'experiments' => [
                'exp' => [
                    'universe' => 'u',
                    'allocationPct' => 0, // nobody allocated
                    'salt' => 's',
                    'status' => 'running',
                    'groups' => [['name' => 'treatment', 'weight' => 10000, 'params' => ['button_color' => 'blue']]],
                ],
            ],
        ]);

        $a = $engine->universe('u')->assign(['user_id' => 'u1']);
        $this->assertFalse($a->enrolled());
        $this->assertNull($a->group);
        $this->assertNull($a->name);
        // Not enrolled → universe default, not the variant override.
        $this->assertSame('red', $a->get('button_color'));
    }

    // ---- pooled mutual exclusion (§B4) ----

    public function testPooledMutualExclusion(): void
    {
        // Two experiments in ONE universe, hashVersion 2, disjoint pool slices:
        //   A = [0, 4000), B = [4000, 8000). Segment >= 8000 is free headroom.
        $engine = self::makeEngine([], [
            'universes' => ['u' => ['holdout_range' => null]],
            'experiments' => [
                'expA' => [
                    'universe' => 'u',
                    'hashVersion' => 2,
                    'poolOffsetBp' => 0,
                    'poolSizeBp' => 4000,
                    'allocationPct' => 10000,
                    'salt' => 'sA',
                    'status' => 'running',
                    'groups' => [['name' => 'A', 'weight' => 10000, 'params' => []]],
                ],
                'expB' => [
                    'universe' => 'u',
                    'hashVersion' => 2,
                    'poolOffsetBp' => 4000,
                    'poolSizeBp' => 4000,
                    'allocationPct' => 10000,
                    'salt' => 'sB',
                    'status' => 'running',
                    'groups' => [['name' => 'B', 'weight' => 10000, 'params' => []]],
                ],
            ],
        ]);

        $inA = 0;
        $inB = 0;
        $neither = 0;
        for ($i = 0; $i < 400; $i++) {
            $uid = "u$i";
            $a = $engine->universe('u')->assign(['user_id' => $uid]);
            // assign returns ≤1 experiment, so double-enrolment is impossible by
            // design; cross-check the landing against the unit's own segment.
            $seg = self::universeSeg('u', $uid);
            if ($a->name === 'expA') {
                $inA++;
                $this->assertLessThan(4000, $seg);
            } elseif ($a->name === 'expB') {
                $inB++;
                $this->assertGreaterThanOrEqual(4000, $seg);
                $this->assertLessThan(8000, $seg);
            } else {
                $neither++;
                $this->assertFalse($a->enrolled());
                $this->assertGreaterThanOrEqual(8000, $seg);
            }
        }
        // The partition is real: all three buckets are populated over 400 users.
        $this->assertGreaterThan(0, $inA);
        $this->assertGreaterThan(0, $inB);
        $this->assertGreaterThan(0, $neither);
        $this->assertSame(400, $inA + $inB + $neither);
    }

    // ---- reserved headroom (§B5) ----

    public function testReservedHeadroomLeavesTailUnassigned(): void
    {
        // 100% allocation, groups summing to 5000 with reservedHeadroomBp 5000:
        // units whose group hash falls in the reserved tail are not-enrolled.
        $engine = self::makeEngine([], [
            'universes' => ['u' => ['holdout_range' => null]],
            'experiments' => [
                'exp' => [
                    'universe' => 'u',
                    'allocationPct' => 10000,
                    'reservedHeadroomBp' => 5000,
                    'salt' => 's',
                    'status' => 'running',
                    'groups' => [['name' => 'control', 'weight' => 5000, 'params' => []]],
                ],
            ],
        ]);

        $enrolled = 0;
        $reserved = 0;
        for ($i = 0; $i < 400; $i++) {
            $a = $engine->universe('u')->assign(['user_id' => "u$i"]);
            if ($a->enrolled()) {
                $enrolled++;
            } else {
                $reserved++;
            }
        }
        // Both populated: allocation is 100% yet the reserved tail carves ~half.
        $this->assertGreaterThan(0, $enrolled);
        $this->assertGreaterThan(0, $reserved);
    }

    // ---- holdoutGate (§B3) ----

    public function testHoldoutGateForcesHoldout(): void
    {
        $engine = self::makeEngine(
            [
                'gates' => [
                    // enabled, 100% rollout, no rules → passes for every unit.
                    'hg' => ['rules' => [], 'rolloutPct' => 10000, 'salt' => 'hg', 'enabled' => 1],
                ],
            ],
            [
                'universes' => ['u' => ['holdout_range' => null]],
                'experiments' => [
                    'exp' => [
                        'universe' => 'u',
                        'holdoutGate' => 'hg',
                        'allocationPct' => 10000,
                        'salt' => 's',
                        'status' => 'running',
                        'groups' => [['name' => 'treatment', 'weight' => 10000, 'params' => []]],
                    ],
                ],
            ],
        );

        $a = $engine->universe('u')->assign(['user_id' => 'u1']);
        $this->assertFalse($a->enrolled());
        $this->assertNull($a->group);
    }
}
