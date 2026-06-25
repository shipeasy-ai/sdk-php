<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * see — shipeasy error. Static helpers for sanitization + wire-event
 * construction backing the see() structured error-reporting API.
 *
 * Mirrors `@shipeasy/sdk` (packages/ts-sdk/src/see/core.ts) and the Python
 * reference (packages/server-sdks/sdk-python/shipeasy/_see.py). The public
 * surface is Engine::see()/seeViolation()/controlFlowException() plus the
 * Shipeasy\see()/seeViolation()/controlFlowException() namespaced functions —
 * this class is internal plumbing.
 */
final class See
{
    private static function truncate(string $s, int $limit): string
    {
        // mb-aware so multibyte values aren't cut mid-codepoint.
        return mb_strlen($s) <= $limit ? $s : mb_substr($s, 0, $limit);
    }

    /**
     * Drop null values, keep only string/finite-number/bool, truncate string
     * values to 200 chars, cap at 20 keys (insertion order). Returns null if
     * nothing kept.
     *
     * @param array<string, mixed>|null $extras
     * @return array<string, mixed>|null
     */
    public static function sanitizeExtras(?array $extras): ?array
    {
        if (!$extras) {
            return null;
        }
        $out = [];
        $n = 0;
        foreach ($extras as $k => $v) {
            if ($v === null) {
                continue;
            }
            if ($n >= SeeLimits::MAX_EXTRA_KEYS) {
                break;
            }
            $key = (string) $k;
            if (is_bool($v)) {
                $out[$key] = $v;
            } elseif (is_string($v)) {
                $out[$key] = self::truncate($v, SeeLimits::MAX_EXTRA_VALUE);
            } elseif (is_int($v)) {
                $out[$key] = $v;
            } elseif (is_float($v)) {
                if (is_nan($v) || is_infinite($v)) {
                    continue;
                }
                $out[$key] = $v;
            } else {
                continue;
            }
            $n++;
        }
        return $out !== [] ? $out : null;
    }

    /**
     * Build the type:"error" event accepted by POST /collect.
     *
     * @param Violation|\Throwable|mixed $problem
     * @param array<string, mixed>|null $extras
     * @return array<string, mixed>
     */
    public static function buildEvent(
        mixed $problem,
        string $subject,
        string $outcome,
        ?array $extras,
        string $side,
        string $sdkVersion,
        ?string $env
    ): array {
        $stack = null;
        if ($problem instanceof Violation) {
            $errorType = $problem->name;
            $message = $problem->name;
            $kind = 'violation';
        } elseif ($problem instanceof \Throwable) {
            $errorType = get_class($problem);
            $message = $problem->getMessage();
            if ($message === '') {
                $message = $errorType;
            }
            try {
                $stack = $problem->getTraceAsString();
                if ($stack === '') {
                    $stack = null;
                }
            } catch (\Throwable) {
                $stack = null;
            }
            $kind = 'caught';
        } else {
            $errorType = 'Error';
            $message = is_string($problem)
                ? $problem
                : (is_scalar($problem) ? (string) $problem : self::stringify($problem));
            $kind = 'caught';
        }

        $ev = [
            'type' => 'error',
            'kind' => $kind,
            'error_type' => self::truncate((string) $errorType, SeeLimits::MAX_SUBJECT),
            'message' => self::truncate((string) $message, SeeLimits::MAX_MESSAGE),
            'subject' => self::truncate($subject, SeeLimits::MAX_SUBJECT),
            'outcome' => self::truncate($outcome, SeeLimits::MAX_SUBJECT),
            'side' => $side,
            'sdk_version' => $sdkVersion,
            'ts' => (int) (microtime(true) * 1000),
        ];
        if ($stack !== null) {
            $ev['stack'] = self::truncate($stack, SeeLimits::MAX_STACK);
        }
        $clean = self::sanitizeExtras($extras);
        if ($clean !== null) {
            $ev['extras'] = $clean;
        }
        if ($env !== null && $env !== '') {
            $ev['env'] = $env;
        }
        return $ev;
    }

    private static function stringify(mixed $v): string
    {
        $json = json_encode($v, JSON_UNESCAPED_UNICODE);
        return $json !== false ? $json : 'Error';
    }
}
