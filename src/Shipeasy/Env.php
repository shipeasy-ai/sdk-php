<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * Native runtime-environment detection.
 *
 * Used ONLY to pick the DEFAULT for outbound egress when the caller does not set
 * it explicitly:
 *   - is the SDK allowed to make network requests at all (`isNetworkEnabled`)?
 *   - is per-evaluation usage telemetry / logging allowed (the telemetry toggle)?
 *
 * Both default to ON in production and OFF everywhere else, so a local/dev/CI
 * run of an app that embeds the SDK never phones home unless it explicitly opts
 * in. Mirrors the TypeScript reference SDK (`src/env.ts`).
 *
 * Precedence for the production decision:
 *   1. A native runtime env var — `SHIPEASY_ENV`, then `APP_ENV` (the
 *      Laravel/Symfony convention), then `ENV`. A value of "production"/"prod"
 *      (case-insensitive) ⇒ prod; anything else ("development"/"staging"/"test"/…)
 *      ⇒ not prod.
 *   2. When no native env var is set (common on serverless / CLI hosts), fall
 *      back to the SDK's own configured `env` option, which the caller sets and
 *      which itself defaults to "prod". This keeps a real production deploy "on"
 *      by default while an `env: "dev"` config stays quiet.
 *
 * The `env` option is always present (it defaults to "prod"), so the production
 * decision is always inferrable — the SDK never has to make the field required.
 */
final class Env
{
    /**
     * True when the host runtime looks like a production deployment.
     * $configuredEnv is the SDK's own `env` option (dev/staging/prod); it is
     * consulted only when no native runtime env var is set.
     */
    public static function isProductionEnv(?string $configuredEnv = null): bool
    {
        $native = self::readNativeEnv();
        if ($native !== null) {
            return $native === 'production' || $native === 'prod';
        }
        $env = $configuredEnv ?? 'prod';
        return strtolower(trim($env)) === 'prod';
    }

    /**
     * Read the native runtime environment string, lowercased, or null when none
     * of the recognised vars is set. Checks `SHIPEASY_ENV`, then `APP_ENV`, then
     * `ENV`, looking in getenv(), $_ENV and $_SERVER (so it works regardless of
     * how the host populates env vars).
     */
    private static function readNativeEnv(): ?string
    {
        foreach (['SHIPEASY_ENV', 'APP_ENV', 'ENV'] as $name) {
            $raw = self::lookup($name);
            if ($raw !== null) {
                $v = strtolower(trim($raw));
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return null;
    }

    /** Look one env var up across getenv()/$_ENV/$_SERVER; null when unset/non-string. */
    private static function lookup(string $name): ?string
    {
        $val = getenv($name);
        if (is_string($val) && $val !== '') {
            return $val;
        }
        foreach ([$_ENV, $_SERVER] as $bag) {
            if (isset($bag[$name]) && is_string($bag[$name]) && $bag[$name] !== '') {
                return $bag[$name];
            }
        }
        return null;
    }
}
