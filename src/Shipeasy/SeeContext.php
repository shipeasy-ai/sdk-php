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
 */
final class SeeContext
{
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
}
