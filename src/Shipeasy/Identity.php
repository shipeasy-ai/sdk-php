<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * Anonymous bucketing identity — the cross-SDK `__se_anon_id` cookie.
 *
 * Gates and experiments bucket a unit with murmur3(salt:unit). For a logged-out
 * visitor the unit is a stable anonymous id carried in a single first-party
 * cookie that EVERY Shipeasy SDK (server + browser) reads and writes, so a
 * server render and the browser bucket a fractional rollout identically. The
 * cookie name + format are frozen across every language; see
 * experiment-platform/18-identity-bucketing.md.
 *
 * Usage — call once early in your bootstrap (before any output), then evaluate
 * as normal; logged-out requests bucket on the cookie automatically:
 *
 *     \Shipeasy\Identity::ensure();
 *     $client->getFlag('new_checkout', []); // defaults to the __se_anon_id
 *
 * Works in plain PHP, WordPress, Laravel, Symfony, Slim — anywhere `$_COOKIE`
 * and `setcookie()` are available (PHP-FPM, mod_php, CLI server).
 */
final class Identity
{
    public const COOKIE = '__se_anon_id';
    public const MAX_AGE = 31536000; // 1 year, in seconds

    private static ?string $current = null;

    /** A fresh opaque bucketing id (UUIDv4). */
    public static function mint(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); // version 4
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); // variant 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function isValid(?string $v): bool
    {
        return is_string($v) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $v) === 1;
    }

    /**
     * The anon id resolved for this request, or null if ensure() hasn't run.
     * Engine::getFlag/getExperiment fall back to this as the default
     * anonymous_id, so evaluations need no per-call wiring.
     */
    public static function current(): ?string
    {
        return self::$current;
    }

    /**
     * Read `__se_anon_id` off the request, minting + Set-Cookie-ing one when
     * absent or tampered, and make it the default bucketing unit for this
     * request. Returns the resolved id. Pass $secure to override HTTPS
     * detection. No-op on the cookie if headers were already sent.
     */
    public static function ensure(?bool $secure = null): string
    {
        $raw = $_COOKIE[self::COOKIE] ?? null;
        if (self::isValid($raw)) {
            return self::$current = $raw;
        }

        $id = self::mint();
        if ($secure === null) {
            $secure = (($_SERVER['HTTPS'] ?? '') === 'on')
                || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        }
        if (!headers_sent()) {
            setcookie(self::COOKIE, $id, [
                'expires' => time() + self::MAX_AGE,
                'path' => '/',
                'samesite' => 'Lax',
                'secure' => $secure,
                'httponly' => false, // browser SDK reads it via document.cookie
            ]);
        }
        // So the same request's later reads see the id too.
        $_COOKIE[self::COOKIE] = $id;
        return self::$current = $id;
    }

    /** Reset the resolved id — primarily for tests / long-running workers. */
    public static function reset(): void
    {
        self::$current = null;
    }
}
