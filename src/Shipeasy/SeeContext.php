<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * Ambient per-request buffer of see() extras. Fields added here merge into
 * EVERY see() report that fires later in the same request, so a request can
 * attach context (order id, route, tenant) from anywhere without threading it
 * into the catch block:
 *
 *     Shipeasy\addExtras(['order_id' => $order->id, 'tenant' => $tenant->slug]);
 *     // ...later, somewhere else in the same request...
 *     try {
 *         charge($order);
 *     } catch (\Throwable $e) {
 *         Shipeasy\see($e)->causesThe('checkout')->to('use cached prices');
 *         // ^ report carries order_id + tenant automatically
 *     }
 *
 * PHP is share-nothing per request: under PHP-FPM / mod_php the static buffer is
 * naturally request-scoped — a fresh worker process (or a reset one) starts
 * empty and the next request never inherits it. Under a long-running runtime
 * (Swoole / RoadRunner / a resident worker loop) the same process serves many
 * requests, so the app MUST call {@see Shipeasy\clearExtras()} at request end to
 * stop context leaking into the next request.
 *
 * Values are stored raw and sanitized (scalar-only, truncated, 20-key cap,
 * private-attribute stripped) at build time, exactly like chained extras. The
 * chain's own extras merge OVER this buffer, so a chained key of the same name
 * wins over an ambient one. Mirrors the Ruby SDK's See::Context.
 *
 * It also resolves the browser SDK's per-request correlation token: the browser
 * sends it on the `X-SE-Correlation` header of every same-origin request, and
 * every see() fired while serving that request carries it as `correlation_id`
 * so the platform can link the browser's error to the server exception behind
 * it. Read lazily from `$_SERVER['HTTP_X_SE_CORRELATION']` at report time, the
 * same way {@see Identity} reads the anon-id cookie from `$_COOKIE`.
 */
final class SeeContext
{
    /** `$_SERVER` key PHP populates from the `X-SE-Correlation` request header. */
    public const CORRELATION_SERVER_KEY = 'HTTP_X_SE_CORRELATION';

    /** Same shape the collector accepts; anything else is dropped, never sent. */
    private const CORRELATION_RE = '/\A[A-Za-z0-9-]{8,64}\z/';

    /** @var array<string, mixed> */
    private static array $buffer = [];

    /**
     * Merge fields into the current request's buffer (string keys, later wins).
     * A non-empty array is required; anything else is ignored. Never throws.
     *
     * @param array<string, mixed> $extras
     */
    public static function add(array $extras): void
    {
        if ($extras === []) {
            return;
        }
        foreach ($extras as $k => $v) {
            self::$buffer[(string) $k] = $v;
        }
    }

    /**
     * A copy of the current buffer, or [] when empty.
     *
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        return self::$buffer;
    }

    /**
     * Drop the buffer so extras never leak into the next request handled by this
     * process (required under long-running runtimes; a no-op-safe reset under
     * PHP-FPM).
     */
    public static function clear(): void
    {
        self::$buffer = [];
    }

    /**
     * The current request's `X-SE-Correlation` token, or null when the header is
     * absent or malformed. Never throws.
     */
    public static function correlationId(): ?string
    {
        try {
            return self::cleanCorrelation($_SERVER[self::CORRELATION_SERVER_KEY] ?? null);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The trimmed token when it matches `[A-Za-z0-9-]{8,64}`, else null. */
    public static function cleanCorrelation(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $s = trim($value);
        return preg_match(self::CORRELATION_RE, $s) === 1 ? $s : null;
    }
}
