<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * Request-scoped holder for the verified signed DevTools overrides (`se_ov`).
 *
 * Two ways in:
 *  - automatic (classic PHP-FPM): when no explicit scope is active, the first
 *    read picks up `$_COOKIE['se_ov']`;
 *  - explicit: {@see \Shipeasy\withRequestOverrides()} scopes a raw `Cookie`
 *    header around a callable (Laravel/Symfony/PSR-15/Swoole/RoadRunner glue).
 *
 * The cookie is verified once per (engine, raw value) and memoized, never per
 * read. Fail closed: no engine key, bad signature, expired — no overrides.
 * State is process-static, so on a runtime that interleaves requests in one
 * process (Swoole coroutines) scope per request on a non-concurrent worker.
 *
 * @internal Use {@see \Shipeasy\withRequestOverrides()}.
 */
final class RequestOverrides
{
    /** @var array{raw: ?string, header: ?string}|null Active explicit scope, or null. */
    private static ?array $scope = null;

    /** @var array<string, array|null> memo: engine-id|key|raw => verified map */
    private static array $memo = [];

    /**
     * Run $fn with $cookieHeader (a `Cookie:` header or bare `se_ov` value)
     * scoped as the current request's overrides. Always clears afterwards
     * (restoring any outer scope). A null header scopes "no overrides" and
     * suppresses the `$_COOKIE` auto-pickup inside $fn.
     */
    public static function run(?string $cookieHeader, callable $fn): mixed
    {
        $prev = self::$scope;
        self::$scope = ['raw' => OverrideCookie::extract($cookieHeader), 'header' => $cookieHeader];
        try {
            return $fn();
        } finally {
            self::$scope = $prev;
        }
    }

    /**
     * The verified override map for $engine in the current request, or null.
     *
     * @return array{gates: array<string,bool>, configs: array<string,mixed>, exps: array<string,string>, exp: int}|null
     */
    public static function current(Engine $engine): ?array
    {
        try {
            $signed = self::signed($engine);
            $dev = null;
            // Zero work unless the opt-in is on AND the process is non-production.
            if ($engine->devOverridesActive()) {
                $devRaw = self::$scope !== null
                    ? OverrideCookie::extractNamed(self::$scope['header'], OverrideCookie::DEV_COOKIE_NAME)
                    : (isset($_COOKIE[OverrideCookie::DEV_COOKIE_NAME]) && is_string($_COOKIE[OverrideCookie::DEV_COOKIE_NAME])
                        ? $_COOKIE[OverrideCookie::DEV_COOKIE_NAME]
                        : null);
                $dev = $devRaw === null ? null : OverrideCookie::decodeDev($devRaw);
            }
            if ($dev === null) {
                return $signed;
            }
            if ($signed === null) {
                return $dev + ['exp' => PHP_INT_MAX];
            }
            // Per-name: the signed cookie wins on conflicts.
            return [
                'gates' => array_replace($dev['gates'], $signed['gates']),
                'configs' => array_replace($dev['configs'], $signed['configs']),
                'exps' => array_replace($dev['exps'], $signed['exps']),
                'exp' => $signed['exp'],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array{gates: array<string,bool>, configs: array<string,mixed>, exps: array<string,string>, exp: int}|null */
    private static function signed(Engine $engine): ?array
    {
        $raw = self::$scope !== null
            ? self::$scope['raw']
            : OverrideCookie::extract(isset($_COOKIE[OverrideCookie::COOKIE_NAME]) && is_string($_COOKIE[OverrideCookie::COOKIE_NAME])
                ? $_COOKIE[OverrideCookie::COOKIE_NAME]
                : null);
        if ($raw === null) {
            return null;
        }
        $k = $engine->overrideKeyInfo();
        if ($k === null) {
            return null; // no key served -> never trust (not memoized: the blob may still load)
        }
        $memoKey = spl_object_id($engine) . '|' . $k[1] . '|' . $k[0] . '|' . $raw;
        if (!array_key_exists($memoKey, self::$memo)) {
            if (count(self::$memo) > 32) {
                self::$memo = [];
            }
            self::$memo[$memoKey] = OverrideCookie::verify($raw, $k[0], $k[1]);
        }
        $ov = self::$memo[$memoKey];
        // Re-check expiry on memo hits (long-running workers).
        return $ov !== null && $ov['exp'] > time() ? $ov : null;
    }

    /** Clear scope + memo (tests). */
    public static function reset(): void
    {
        self::$scope = null;
        self::$memo = [];
    }
}
