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

    private static function clampPct(int $n): int
    {
        if ($n < 0) return 0;
        if ($n > 10000) return 10000;
        return $n;
    }

    /**
     * Effective rollout % (basis points) for a stack entry at time $now (epoch
     * ms). A condition with no explicit rolloutPct defaults to 100%
     * (match ⇒ pass); a rollout to 0%. A `ramp` overrides the static % via
     * truncating-toward-zero integer division — the cross-SDK contract
     * (experiment-platform/04-evaluation.md). Mirrors @shipeasy/core effectivePct.
     *
     * @param array<string,mixed> $entry
     */
    private static function effectivePct(array $entry, int $now): int
    {
        $isCondition = ($entry['type'] ?? null) === 'condition';
        $base = $isCondition
            ? (int) ($entry['rolloutPct'] ?? 10000)
            : (int) ($entry['rolloutPct'] ?? 0);
        $ramp = $entry['ramp'] ?? null;
        if (!is_array($ramp)) return $base;
        $from = (int) ($ramp['from'] ?? 0);
        $to = (int) ($ramp['to'] ?? 0);
        $startAt = (int) ($ramp['startAt'] ?? 0);
        $durationMs = (int) ($ramp['durationMs'] ?? 0);
        if ($now <= $startAt) return $from;
        if ($durationMs <= 0 || $now >= $startAt + $durationMs) return $to;
        $delta = $to - $from; // signed
        $elapsed = $now - $startAt;
        // Truncate toward zero — `(int)` of the signed float matches Math.trunc.
        $pct = $from + (int) ((float) $delta * $elapsed / $durationMs);
        return self::clampPct($pct);
    }

    /**
     * Hash the caller into `[0, 10000)` and test against $pct. No-unit contract
     * (experiment-platform/18): a fully-rolled bucket is on for everyone without
     * a unit id; a fractional one needs a stable unit, so it is off.
     */
    private static function bucketHit(int $pct, ?string $uid, string $salt): bool
    {
        if ($pct <= 0) return false;
        if ($uid === null || $uid === '') return $pct >= 10000;
        if ($pct >= 10000) return true;
        return Murmur3::hash32("$salt:$uid") % 10000 < $pct;
    }

    /**
     * Evaluate one ordered gatekeeper stack entry. A `condition` gates on its
     * rules (all, or any with `pass:'any'`) then buckets at its own rollout;
     * a `rollout` buckets everyone who reached it. Mirrors @shipeasy/core
     * evalStackEntry — keep the two in sync.
     *
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $user
     */
    private static function evalStackEntry(array $entry, array $user, string $fallbackSalt, int $now): bool
    {
        if (($entry['type'] ?? null) === 'condition') {
            $rules = $entry['rules'] ?? [];
            if (!is_array($rules) || $rules === []) return false;
            $mode = $entry['pass'] ?? 'all';
            if ($mode === 'any') {
                $matched = false;
                foreach ($rules as $r) {
                    if (self::matchRule($r, $user)) { $matched = true; break; }
                }
            } else {
                $matched = true;
                foreach ($rules as $r) {
                    if (!self::matchRule($r, $user)) { $matched = false; break; }
                }
            }
            if (!$matched) return false;
            // Rules matched — bucket at the per-condition rollout. A distinct
            // default salt (the entry id) keeps each step's bucket independent
            // yet stable across edits.
            $entrySalt = $entry['salt'] ?? null;
            $salt = (is_string($entrySalt) && $entrySalt !== '')
                ? $entrySalt
                : (string) ($entry['id'] ?? $fallbackSalt);
            $bucketBy = $entry['bucketBy'] ?? null;
            return self::bucketHit(
                self::effectivePct($entry, $now),
                self::pickIdentifier($user, is_string($bucketBy) ? $bucketBy : null),
                $salt
            );
        }
        // rollout — salt fallback is the gate salt so existing entries don't re-bucket.
        $entrySalt = $entry['salt'] ?? null;
        $salt = (is_string($entrySalt) && $entrySalt !== '') ? $entrySalt : $fallbackSalt;
        $bucketBy = $entry['bucketBy'] ?? null;
        return self::bucketHit(
            self::effectivePct($entry, $now),
            self::pickIdentifier($user, is_string($bucketBy) ? $bucketBy : null),
            $salt
        );
    }

    public static function evalGate(?array $gate, array $user): bool
    {
        if ($gate === null) return false;
        if (self::enabled($gate['killswitch'] ?? null)) return false;
        if (!self::enabled($gate['enabled'] ?? null)) return false;

        // Modern gatekeepers ship an ordered `stack`; evaluate it top-to-bottom and
        // pass on the first entry whose rules match AND whose bucket hits. This is
        // the canonical model — the flat `rules`/`rolloutPct` below are a lossy
        // approximation (a whitelist condition at 100% collapses to `rolloutPct: 0`
        // once the public rollout is 0%, which the flat path would wrongly read as
        // "never"). Mirrors @shipeasy/core evalGatekeeper — keep the two in sync.
        $stack = $gate['stack'] ?? null;
        if (is_array($stack) && $stack !== []) {
            $now = (int) (microtime(true) * 1000);
            $salt = (string) ($gate['salt'] ?? '');
            foreach ($stack as $entry) {
                if (is_array($entry) && self::evalStackEntry($entry, $user, $salt, $now)) return true;
            }
            return false;
        }

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
     * Flatten a universe param schema to a plain `name => default` map — the
     * defaults `assign()` layers under a variant's override map. Returns null for
     * a null/empty schema so the merge short-circuits. Mirrors @shipeasy/core.
     *
     * @param mixed $schema array of `{name, type, default}`, or null/empty
     * @return array<string, mixed>|null
     */
    public static function paramDefaultsFromSchema(mixed $schema): ?array
    {
        if (!is_array($schema) || $schema === []) return null;
        $out = [];
        foreach ($schema as $p) {
            if (is_array($p) && isset($p['name'])) {
                $out[(string) $p['name']] = $p['default'] ?? null;
            }
        }
        return $out === [] ? null : $out;
    }

    /**
     * `universeDefaults ⊕ variantOverride` — a variant inherits every universe
     * default it doesn't explicitly override.
     *
     * @param array<string, mixed>|null $paramDefaults
     * @param mixed $groupParams the variant's own params (array, or null/other)
     * @return array<string, mixed>
     */
    public static function mergeParams(?array $paramDefaults, mixed $groupParams): array
    {
        $override = is_array($groupParams) ? $groupParams : [];
        return $paramDefaults !== null ? array_merge($paramDefaults, $override) : $override;
    }

    /**
     * Evaluate an experiment for a user.
     *
     * Targeting → universe holdout → holdout gate → sticky → allocation (pooled
     * or legacy) → weighted group split. The single local mirror of
     * @shipeasy/core's `classifyExperiment` (doc 20 §B) — keep the two in sync.
     * The returned {@see ExperimentResult}'s `params` are the assigned variant's
     * params merged UNDER the universe param-schema defaults (variant wins);
     * `null` when not enrolled.
     *
     * When a {@see StickyBucketStore} is supplied (with the experiment $name so
     * it can be keyed), an enrolled unit whose stored salt prefix still matches
     * skips the allocation gate and returns the stored group without re-running
     * the pick — so a shrinking allocation keeps it in. A fresh pick is written
     * back via the store. A salt mismatch or a vanished stored group falls
     * through to re-bucket + overwrite. Absent store ⇒ deterministic (no I/O).
     */
    /**
     * Resolve a forced override group for $uid (spec step 1): ID overrides
     * (tier 1) beat cohort/GK overrides (tier 2); within cohort overrides the
     * first (pre-sorted by priority) gate that passes wins. Returns the forced
     * group name or null. The caller applies eligibility + group-existence
     * (forced-but-gated). Mirrors @shipeasy/core resolveForcedGroup.
     *
     * @param array<string,mixed> $exp
     * @param callable(string):bool $evalGate
     */
    private static function resolveForcedGroup(array $exp, string $uid, callable $evalGate): ?string
    {
        $idOverrides = $exp['idOverrides'] ?? null;
        if (is_array($idOverrides)) {
            $byId = $idOverrides[$uid] ?? null;
            if (is_string($byId) && $byId !== '') return $byId;
        }
        $cohortOverrides = $exp['cohortOverrides'] ?? null;
        if (is_array($cohortOverrides)) {
            foreach ($cohortOverrides as $co) {
                $gname = $co['gate'] ?? null;
                if (is_string($gname) && $evalGate($gname)) {
                    return isset($co['group']) ? (string) $co['group'] : null;
                }
            }
        }
        return null;
    }

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

        $universeName = $exp['universe'] ?? null;
        $universe = is_string($universeName) ? ($exps['universes'][$universeName] ?? null) : null;
        $paramDefaults = self::paramDefaultsFromSchema(
            is_array($universe) ? ($universe['param_schema'] ?? null) : null
        );

        // A gate-name → bool lookup over the flags blob, reused by the two gate
        // checks (targeting + holdout gate) so they run the SDK's real gate eval.
        $evalGate = static function (string $gname) use ($flags, $user): bool {
            $gate = $flags['gates'][$gname] ?? null;
            return $gate !== null && self::evalGate($gate, $user);
        };

        // Return an enrolled result with universe defaults merged under the
        // variant's own params (variant wins).
        $asGroup = fn (string $groupName, mixed $groupParams): ExperimentResult =>
            new ExperimentResult(true, $groupName, self::mergeParams($paramDefaults, $groupParams));

        $tg = $exp['targetingGate'] ?? null;
        if (is_string($tg) && $tg !== '' && !$evalGate($tg)) return $notIn;

        $bucketBy = $exp['bucketBy'] ?? null;
        $uid = self::pickIdentifier($user, is_string($bucketBy) ? $bucketBy : null);
        if ($uid === null) return $notIn;

        // One segment in the universe's shared `[0, 10000)` hash space. The
        // holdout carve-out AND every experiment's pool slice are disjoint ranges
        // of THIS segment — that's what makes "held out / taken / free" a real
        // partition.
        $universeSeg = is_string($universeName)
            ? Murmur3::hash32("$universeName:$uid") % 10000
            : Murmur3::hash32(":$uid") % 10000;

        if (is_array($universe)) {
            $holdout = $universe['holdout_range'] ?? null;
            if (is_array($holdout) && count($holdout) === 2) {
                if ($universeSeg >= (int) $holdout[0] && $universeSeg <= (int) $holdout[1]) return $notIn;
            }
        }

        $holdoutGate = $exp['holdoutGate'] ?? null;
        if (is_string($holdoutGate) && $holdoutGate !== '' && $evalGate($holdoutGate)) return $notIn;

        $salt = $exp['salt'] ?? '';
        $groups = $exp['groups'] ?? [];
        $salt8 = substr((string) $salt, 0, 8);

        // Durable overrides (spec step 1, forced-but-gated). Reached only after the
        // unit passes targeting and is not held out, so an override may now pin the
        // group — bypassing allocation + the weighted pick but NOT the gates above.
        // ID overrides (tier 1) beat cohort/GK overrides (tier 2); a forced group
        // that no longer exists falls through to normal allocation. No-op when
        // unconfigured, so v1/v2 stay byte-identical. Mirrors @shipeasy/core.
        $forced = self::resolveForcedGroup($exp, $uid, $evalGate);
        if ($forced !== null) {
            foreach ($groups as $g) {
                if ((string) ($g['name'] ?? '') === $forced) {
                    if ($stickyStore !== null && $name !== null) {
                        $stickyStore->set($uid, $name, ['g' => $forced, 's' => $salt8]);
                    }
                    return $asGroup($forced, $g['params'] ?? null);
                }
            }
        }

        // Sticky short-circuit (doc 20 §2): after holdout, before allocation —
        // an enrolled unit whose stored salt prefix still matches returns the
        // stored group without re-bucketing (skips the allocation gate).
        if ($stickyStore !== null && $name !== null) {
            $entry = $stickyStore->get($uid)[$name] ?? null;
            if (is_array($entry) && ($entry['s'] ?? null) === $salt8) {
                $storedGroup = $entry['g'] ?? null;
                foreach ($groups as $g) {
                    if ((string) ($g['name'] ?? '') === (string) $storedGroup) {
                        return $asGroup((string) $storedGroup, $g['params'] ?? null);
                    }
                }
                // Stored group is gone — fall through to re-bucket + overwrite.
            }
        }

        // Allocation. Pooled (hashVersion ≥ 2 with a slice) gives real mutual
        // exclusion: the unit's universe segment must fall in the claimed range.
        // Legacy falls back to an independent per-experiment salt so siblings
        // overlap freely.
        $hashVersion = (int) ($exp['hashVersion'] ?? 1);
        $poolOffset = $exp['poolOffsetBp'] ?? null;
        $poolSize = $exp['poolSizeBp'] ?? null;
        $pooled = $hashVersion >= 2 && $poolOffset !== null && $poolSize !== null && (int) $poolSize > 0;
        if ($pooled) {
            $lo = (int) $poolOffset;
            $hi = $lo + (int) $poolSize;
            if ($universeSeg < $lo || $universeSeg >= $hi) return $notIn;
        } else {
            $allocPct = (int) ($exp['allocationPct'] ?? 0);
            if (Murmur3::hash32("$salt:alloc:$uid") % 10000 >= $allocPct) return $notIn;
        }

        // Group split over `[0, usable)` where `usable = 10000 − reserved`; a unit
        // in the reserved tail is left unassigned so an appended variant can
        // absorb it (doc 20 §B5).
        $reserved = max(0, min(10000, (int) ($exp['reservedHeadroomBp'] ?? 0)));
        $usable = 10000 - $reserved;
        $groupHash = Murmur3::hash32("$salt:group:$uid") % 10000;
        if ($groupHash >= $usable) return $notIn;
        $cumulative = 0;
        foreach ($groups as $i => $g) {
            $cumulative += (int) ($g['weight'] ?? 0);
            if ($groupHash < $cumulative || $i === count($groups) - 1) {
                $groupName = (string) ($g['name'] ?? 'control');
                if ($stickyStore !== null && $name !== null) {
                    $stickyStore->set($uid, $name, ['g' => $groupName, 's' => $salt8]);
                }
                return $asGroup($groupName, $g['params'] ?? null);
            }
        }
        return $notIn;
    }
}
