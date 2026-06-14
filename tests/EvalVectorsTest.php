<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Eval_;
use Shipeasy\Murmur3;

/**
 * Cross-language eval-parity golden-vector test.
 *
 * The fixture at tests/fixtures/eval-vectors.json is a byte-identical copy of
 * the canonical source of truth, packages/core/src/eval/__fixtures__/eval-vectors.json.
 * Every Shipeasy SDK that reimplements bucketing MUST reproduce every vector;
 * this test guards the PHP SDK against silent drift from the platform.
 *
 * Vectors are loaded from JSON at runtime (never hardcoded) so a regeneration
 * upstream is picked up by re-copying the fixture, not by editing this file.
 */
final class EvalVectorsTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function vectors(): array
    {
        $path = __DIR__ . '/fixtures/eval-vectors.json';
        $raw = file_get_contents($path);
        self::assertNotFalse($raw, "could not read fixture: $path");
        $data = json_decode($raw, true);
        self::assertIsArray($data, 'fixture did not decode to an array');
        return $data;
    }

    public function testBucketModuloMatchesImplementation(): void
    {
        // The contract is hardwired to % 10000 across all SDKs; assert the
        // fixture still agrees so a modulo change upstream trips this test.
        $this->assertSame(10000, self::vectors()['bucketModulo']);
    }

    public function testHashVectors(): void
    {
        $vectors = self::vectors()['hash'];
        $this->assertNotEmpty($vectors);

        foreach ($vectors as $v) {
            $input = $v['input'];
            $expected = $v['hash'];

            // murmur3 hash32 must equal the canonical uint32 (decimal) exactly.
            $actual = Murmur3::hash32($input);
            $this->assertSame(
                $expected,
                $actual,
                "hash mismatch for input " . json_encode($input) . ": expected $expected, got $actual"
            );

            // Sanity: the value the bucketing math actually consumes is a
            // non-negative uint32, so % 10000 is well-defined on 64-bit PHP.
            $this->assertGreaterThanOrEqual(0, $actual, "hash must be non-negative for input " . json_encode($input));
            $this->assertLessThanOrEqual(0xFFFFFFFF, $actual, "hash must fit uint32 for input " . json_encode($input));
        }
    }

    public function testGateVectors(): void
    {
        $vectors = self::vectors()['gate'];
        $this->assertNotEmpty($vectors);

        foreach ($vectors as $v) {
            $note = $v['note'] ?? '';
            $actual = Eval_::evalGate($v['gate'], $v['user']);
            $this->assertSame(
                $v['pass'],
                $actual,
                "gate eval mismatch ($note)"
            );
        }
    }

    public function testExperimentVectors(): void
    {
        $vectors = self::vectors()['experiment'];
        $this->assertNotEmpty($vectors);

        foreach ($vectors as $v) {
            $note = $v['note'] ?? '';
            $exp = $v['experiment'];
            $user = $v['user'];

            // Fixture `flags` is { gateName: bool }; the PHP eval re-evaluates
            // the targeting gate object at $flags['gates'][name]. Translate each
            // boolean into a gate object that evaluates to that value for $user:
            // true  -> enabled, fully rolled (always on for an identified unit,
            //          and on for no-unit too per the no-unit full-rollout rule);
            // false -> disabled gate (never passes).
            $gates = [];
            foreach (($v['flags'] ?? []) as $name => $on) {
                $gates[$name] = $on
                    ? ['enabled' => true, 'rolloutPct' => 10000, 'salt' => '', 'rules' => []]
                    : ['enabled' => false, 'rolloutPct' => 10000, 'salt' => '', 'rules' => []];
            }
            $flags = ['gates' => $gates];

            // Fixture `holdoutRange` is per-experiment; the PHP eval reads it from
            // $exps['universes'][universe]['holdout_range']. Wire it through.
            $exps = ['universes' => []];
            $holdout = $v['holdoutRange'] ?? null;
            if (is_array($holdout)) {
                $universe = $exp['universe'] ?? null;
                if (is_string($universe)) {
                    $exps['universes'][$universe] = ['holdout_range' => $holdout];
                }
            }

            $result = Eval_::evalExperiment($exp, $flags, $exps, $user);

            $expectedIn = $v['result']['inExperiment'];
            $this->assertSame(
                $expectedIn,
                $result->inExperiment,
                "experiment inExperiment mismatch ($note)"
            );

            if ($expectedIn) {
                // When assigned, the group name must match exactly. (When not
                // assigned, the PHP result carries a 'control' placeholder group,
                // so only assert the group on the in-experiment path.)
                $this->assertSame(
                    $v['result']['group'],
                    $result->group,
                    "experiment group mismatch ($note)"
                );
            }
        }
    }
}
