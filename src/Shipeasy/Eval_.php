<?php

declare(strict_types=1);

namespace Shipeasy;

/** Internal evaluation helpers. Class name avoids the `eval` keyword. */
final class Eval_
{
    private static function enabled(mixed $v): bool
    {
        return $v === true || $v === 1;
    }

    private static function toNum(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) return (float) $v;
        if (is_string($v) && is_numeric($v)) return (float) $v;
        return null;
    }

    private static function userId(array $user): ?string
    {
        $uid = $user['user_id'] ?? $user['anonymous_id'] ?? null;
        return $uid === null ? null : (string) $uid;
    }

    /**
     * Resolve the bucketing unit. With $bucketBy set, bucket on that attribute
     * (a non-empty string as-is, a number stringified) so a whole org moves
     * together; otherwise fall back to user_id ?? anonymous_id (matches gates).
     * See packages/core/src/eval/gate.ts pickIdentifier + doc 20 §4.
     */
    private static function pickIdentifier(array $user, ?string $bucketBy): ?string
    {
        if ($bucketBy !== null && $bucketBy !== '') {
            $v = $user[$bucketBy] ?? null;
            if (is_string($v) && $v !== '') return $v;
            if (is_int($v) || is_float($v)) return (string) $v;
        }
        return self::userId($user);
    }

    private static function matchRule(array $rule, array $user): bool
    {
        $attr = $rule['attr'] ?? null;
        $op = $rule['op'] ?? null;
        $value = $rule['value'] ?? null;
        $actual = $attr !== null ? ($user[$attr] ?? null) : null;

        return match ($op) {
            'eq' => $actual === $value,
            'neq' => $actual !== $value,
            'in' => is_array($value) && in_array($actual, $value, true),
            'not_in' => !is_array($value) || !in_array($actual, $value, true),
            'contains' => match (true) {
                is_string($actual) && is_string($value) => str_contains($actual, $value),
                is_array($actual) => in_array($value, $actual, true),
                default => false,
            },
            'regex' => is_string($actual) && is_string($value) && @preg_match("~$value~", $actual) === 1,
            'gt', 'gte', 'lt', 'lte' => (function () use ($actual, $value, $op) {
                $a = self::toNum($actual); $b = self::toNum($value);
                if ($a === null || $b === null) return false;
                return match ($op) { 'gt' => $a > $b, 'gte' => $a >= $b, 'lt' => $a < $b, 'lte' => $a <= $b };
            })(),
            default => false,
        };
    }

    public static function evalGate(?array $gate, array $user): bool
    {
        if ($gate === null) return false;
        if (self::enabled($gate['killswitch'] ?? null)) return false;
        if (!self::enabled($gate['enabled'] ?? null)) return false;
        foreach (($gate['rules'] ?? []) as $rule) {
            if (!self::matchRule($rule, $user)) return false;
        }
        $uid = self::userId($user);
        if ($uid === null) {
            // No unit id (an unidentified request before any anon id is minted):
            // a fully-rolled gate is on for everyone, so it can be answered
            // without bucketing; a fractional rollout needs a stable unit, so
            // deny until one exists. Rules above still apply, so targeting wins.
            // See experiment-platform/18-identity-bucketing.md.
            return ((int) ($gate['rolloutPct'] ?? 0)) >= 10000;
        }
        $salt = $gate['salt'] ?? '';
        $rolloutPct = (int) ($gate['rolloutPct'] ?? 0);
        return Murmur3::hash32("$salt:$uid") % 10000 < $rolloutPct;
    }

    /**
     * Evaluate an experiment for a user.
     *
     * When a {@see StickyBucketStore} is supplied (with the experiment $name so
     * it can be keyed), an enrolled unit whose stored salt prefix still matches
     * skips the allocation gate and returns the stored group without re-running
     * the pick — so a shrinking allocation keeps it in. A fresh pick is written
     * back via the store. A salt mismatch or a vanished stored group falls
     * through to re-bucket + overwrite. Absent store ⇒ deterministic (no I/O).
     */
    public static function evalExperiment(
        ?array $exp,
        ?array $flags,
        ?array $exps,
        array $user,
        ?StickyBucketStore $stickyStore = null,
        ?string $name = null
    ): ExperimentResult {
        $notIn = new ExperimentResult(false, 'control', null);
        if ($exp === null || ($exp['status'] ?? null) !== 'running') return $notIn;

        $tg = $exp['targetingGate'] ?? null;
        if (is_string($tg) && $tg !== '') {
            $gate = $flags['gates'][$tg] ?? null;
            if ($gate === null || !self::evalGate($gate, $user)) return $notIn;
        }

        $bucketBy = $exp['bucketBy'] ?? null;
        $uid = self::pickIdentifier($user, is_string($bucketBy) ? $bucketBy : null);
        if ($uid === null) return $notIn;

        $universeName = $exp['universe'] ?? null;
        if (is_string($universeName)) {
            $universe = $exps['universes'][$universeName] ?? null;
            $holdout = $universe['holdout_range'] ?? null;
            if (is_array($holdout) && count($holdout) === 2) {
                $seg = Murmur3::hash32("$universeName:$uid") % 10000;
                if ($seg >= (int)$holdout[0] && $seg <= (int)$holdout[1]) return $notIn;
            }
        }

        $salt = $exp['salt'] ?? '';
        $groups = $exp['groups'] ?? [];
        $salt8 = substr((string) $salt, 0, 8);

        // Sticky short-circuit (doc 20 §2): after holdout, before allocation —
        // an enrolled unit whose stored salt prefix still matches returns the
        // stored group without re-bucketing (skips the allocation gate).
        if ($stickyStore !== null && $name !== null) {
            $entry = $stickyStore->get($uid)[$name] ?? null;
            if (is_array($entry) && ($entry['s'] ?? null) === $salt8) {
                $storedGroup = $entry['g'] ?? null;
                foreach ($groups as $g) {
                    if ((string) ($g['name'] ?? '') === (string) $storedGroup) {
                        return new ExperimentResult(true, (string) $storedGroup, $g['params'] ?? null);
                    }
                }
                // Stored group is gone — fall through to re-bucket + overwrite.
            }
        }

        $allocPct = (int) ($exp['allocationPct'] ?? 0);
        if (Murmur3::hash32("$salt:alloc:$uid") % 10000 >= $allocPct) return $notIn;

        $groupHash = Murmur3::hash32("$salt:group:$uid") % 10000;
        $cumulative = 0;
        foreach ($groups as $i => $g) {
            $cumulative += (int) ($g['weight'] ?? 0);
            if ($groupHash < $cumulative || $i === count($groups) - 1) {
                $groupName = (string) ($g['name'] ?? 'control');
                if ($stickyStore !== null && $name !== null) {
                    $stickyStore->set($uid, $name, ['g' => $groupName, 's' => $salt8]);
                }
                return new ExperimentResult(true, $groupName, $g['params'] ?? null);
            }
        }
        return $notIn;
    }
}
