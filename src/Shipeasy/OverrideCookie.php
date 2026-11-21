<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * Signed DevTools override cookie (`se_ov`) verifier.
 *
 * VENDORED copy of the canonical implementation in the shipeasy monorepo
 * (packages/core/src/overrides/cookie.ts). It MUST stay byte-compatible with
 * core: the derivation formulas and the body signing are shared across every
 * SDK and guarded by the cross-impl fixture in tests/OverrideCookieTest.php.
 *
 * Cookie value = base64url(JSON payload) . "." . base64url(HMAC-SHA256(Ks, body))
 * where Ks = HMAC-SHA256(Kp, "se-ov-sess:v1:<nonce>:<exp>") and Kp is the
 * project verify key the platform serves as `overrideKey` in /sdk/flags.
 * Verification fails CLOSED: any problem yields null (no overrides) — it never
 * throws to the caller.
 *
 * @internal Not part of the documented public surface.
 */
final class OverrideCookie
{
    public const COOKIE_NAME = 'se_ov';
    /** Unsigned, dev-only URL-override cookie (opt-in `devOverrides`, never in production). */
    public const DEV_COOKIE_NAME = 'se_ov_dev';
    public const FORMAT_VERSION = 1;

    /** base64url (no padding) of raw bytes. */
    public static function b64uEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Inverse of {@see b64uEncode()}; tolerates missing padding. Null on invalid input. */
    public static function b64uDecode(string $s): ?string
    {
        $pad = strlen($s) % 4 === 0 ? '' : str_repeat('=', 4 - (strlen($s) % 4));
        $out = base64_decode(strtr($s, '-_', '+/') . $pad, true);
        return $out === false ? null : $out;
    }

    /**
     * Verify a `se_ov` cookie value against the project key `Kp` (base64url, as
     * received in the flags payload). Returns the override map
     * `['gates' => [...], 'configs' => [...], 'exps' => [...]]` or null.
     *
     * @return array{gates: array<string,bool>, configs: array<string,mixed>, exps: array<string,string>, exp: int}|null
     */
    public static function verify(
        string $cookieValue,
        string $encodedProjectKey,
        string $keyId,
        ?int $nowSeconds = null,
    ): ?array {
        try {
            $projectKey = self::b64uDecode($encodedProjectKey);
            if ($projectKey === null) {
                return null;
            }
            $dot = strpos($cookieValue, '.');
            if ($dot === false || $dot <= 0 || $dot !== strrpos($cookieValue, '.')) {
                return null;
            }
            $body = substr($cookieValue, 0, $dot);
            $sig = substr($cookieValue, $dot + 1);

            $json = self::b64uDecode($body);
            if ($json === null) {
                return null;
            }
            $payload = json_decode($json, true);
            if (
                !is_array($payload)
                || ($payload['v'] ?? null) !== self::FORMAT_VERSION
                || !is_string($payload['pid'] ?? null)
                || !is_string($payload['keyId'] ?? null)
                || !is_string($payload['nonce'] ?? null)
                || !(is_int($payload['exp'] ?? null) || is_float($payload['exp'] ?? null))
                || !isset($payload['ov'])
                || !is_array($payload['ov'])
            ) {
                return null;
            }
            if ($payload['keyId'] !== $keyId) {
                return null;
            }
            $now = $nowSeconds ?? time();
            if ($payload['exp'] <= $now) {
                return null;
            }

            $exp = $payload['exp'];
            $expStr = is_float($exp) ? (floor($exp) === $exp ? sprintf('%d', $exp) : (string) $exp) : (string) $exp;
            $sessionKey = hash_hmac('sha256', "se-ov-sess:v1:{$payload['nonce']}:{$expStr}", $projectKey, true);
            $expected = hash_hmac('sha256', $body, $sessionKey, true);
            $given = self::b64uDecode($sig);
            if ($given === null || !hash_equals($expected, $given)) {
                return null;
            }

            $ov = $payload['ov'];
            return [
                'gates' => is_array($ov['gates'] ?? null) ? $ov['gates'] : [],
                'configs' => is_array($ov['configs'] ?? null) ? $ov['configs'] : [],
                'exps' => is_array($ov['exps'] ?? null) ? $ov['exps'] : [],
                'exp' => (int) $exp,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extract the raw `se_ov` value from a bare cookie value or a full `Cookie:`
     * header (`a=1; se_ov=...; b=2`). URL-decodes the value. Null when absent.
     */
    public static function extract(?string $input): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }
        if (!str_contains($input, ';') && !str_contains($input, '=')) {
            return str_contains($input, '.') ? $input : null;
        }
        foreach (explode(';', $input) as $part) {
            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }
            if (trim(substr($part, 0, $eq)) === self::COOKIE_NAME) {
                return rawurldecode(trim(substr($part, $eq + 1)));
            }
        }
        return null;
    }

    /**
     * Pull a named cookie out of a full `Cookie:` header (URL-decoded), or null.
     * Unlike {@see extract()} a bare value is never accepted.
     */
    public static function extractNamed(?string $header, string $name): ?string
    {
        if ($header === null || $header === '' || !str_contains($header, $name . '=')) {
            return null;
        }
        foreach (explode(';', $header) as $part) {
            $eq = strpos($part, '=');
            if ($eq !== false && trim(substr($part, 0, $eq)) === $name) {
                return rawurldecode(trim(substr($part, $eq + 1)));
            }
        }
        return null;
    }

    /**
     * Decode an unsigned `se_ov_dev` value: base64url(JSON
     * `{"gates":{},"configs":{},"exps":{}}`). Null when malformed — never throws.
     *
     * @return array{gates: array<string,bool>, configs: array<string,mixed>, exps: array<string,string>}|null
     */
    public static function decodeDev(string $value): ?array
    {
        try {
            $json = self::b64uDecode($value);
            $ov = $json === null ? null : json_decode($json, true);
            if (!is_array($ov)) {
                return null;
            }
            return [
                'gates' => is_array($ov['gates'] ?? null) ? $ov['gates'] : [],
                'configs' => is_array($ov['configs'] ?? null) ? $ov['configs'] : [],
                'exps' => is_array($ov['exps'] ?? null) ? $ov['exps'] : [],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
