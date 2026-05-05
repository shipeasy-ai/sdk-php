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
        if ($uid === null) return false;
        $salt = $gate['salt'] ?? '';
        $rolloutPct = (int) ($gate['rolloutPct'] ?? 0);
        return Murmur3::hash32("$salt:$uid") % 10000 < $rolloutPct;
    }

    public static function evalExperiment(?array $exp, ?array $flags, ?array $exps, array $user): ExperimentResult
    {
        $notIn = new ExperimentResult(false, 'control', null);
        if ($exp === null || ($exp['status'] ?? null) !== 'running') return $notIn;

        $tg = $exp['targetingGate'] ?? null;
        if (is_string($tg) && $tg !== '') {
            $gate = $flags['gates'][$tg] ?? null;
            if ($gate === null || !self::evalGate($gate, $user)) return $notIn;
        }

        $uid = self::userId($user);
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
        $allocPct = (int) ($exp['allocationPct'] ?? 0);
        if (Murmur3::hash32("$salt:alloc:$uid") % 10000 >= $allocPct) return $notIn;

        $groupHash = Murmur3::hash32("$salt:group:$uid") % 10000;
        $groups = $exp['groups'] ?? [];
        $cumulative = 0;
        foreach ($groups as $i => $g) {
            $cumulative += (int) ($g['weight'] ?? 0);
            if ($groupHash < $cumulative || $i === count($groups) - 1) {
                return new ExperimentResult(true, (string) ($g['name'] ?? 'control'), $g['params'] ?? null);
            }
        }
        return $notIn;
    }
}
