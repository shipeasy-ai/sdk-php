# Error reporting — `see()`

This SDK ships the `see()` error-reporting surface (parity with the TS SDK).
Use it to report a **handled** throwable (or a non-exception "violation") to
Shipeasy as fire-and-forget telemetry, while you keep your normal control flow.

`Shipeasy\see()` is package-level — it reports against the SDK from
`Shipeasy\configure()`. It NEVER throws: before `configure()` runs it logs a
warning and returns a no-op chain. Assumes `Shipeasy\configure()` ran at startup
— see [Installation](installation.md).

## Report a caught throwable

```php
use function Shipeasy\see;

try {
    chargeCard($order);
} catch (\Throwable $e) {
    see($e)
        ->causesThe('checkout')        // the subject — what the error affected (default: 'app')
        ->extras(['order_id' => $id])  // structured debug context attached to the report
        ->to('failed to charge');      // terminal: the consequence (default: 'hit an error')

    return back()->withError('Payment failed');
}
```

The chain is `see($problem)->causesThe($subject)->extras($extras)->to($outcome)`:

- `causesThe(string $subject)` — what the error affected (default `'app'`).
- `extras(array $extras)` — structured debug context attached to the report
  (sanitized: string/int/float/bool only, truncated, ≤20 keys, private attributes
  stripped). Chainable in any order *before* `to()`.
- `to(string $outcome, ?array $extras = null)` — **terminal**; builds the event
  and fire-and-forgets the report. Idempotent (a second `to()` is a no-op). The
  default outcome is `'hit an error'`. Pass `$extras` inline here to fold them in
  as a final `->extras(...)` — so there is no ordering to remember:

```php
see($e)->causesThe('checkout')->to('use cached prices', ['order_id' => $id]);
```

A stray `->extras(...)` chained **after** `->to(...)` is ignored with a warning
(the report already shipped) — crucially, it **never throws** into your catch
block. `->to()` returns the chain so the tail is harmless.

### Attach context from anywhere: `Shipeasy\addExtras`

To attach context without threading it into the catch block, buffer it earlier in
the request with `Shipeasy\addExtras`. Every `see()` report that fires later in
the **same request** merges it in:

```php
use function Shipeasy\addExtras;

// from any layer, early in the request
addExtras(['order_id' => $order->id, 'tenant' => $tenant->slug]);

// ...later, deep in a service...
try {
    chargeCard($order);
} catch (\Throwable $e) {
    see($e)->causesThe('checkout')->to('use cached prices');
    // report carries order_id + tenant automatically
}
```

A chained `->extras` / `->to` extra of the same key overrides an ambient one;
ambient extras are sanitized and private-attribute-stripped like any other.

**PHP is share-nothing per request.** Under PHP-FPM / mod_php the buffer resets
per request automatically — nothing to clean up. Under a **long-running runtime**
(Swoole / RoadRunner / a resident worker loop) the same process serves many
requests, so you MUST call `Shipeasy\clearExtras()` at request end so context
never leaks into the next request.

## Report a non-exception violation

```php
use function Shipeasy\seeViolation;

seeViolation('cart_total_mismatch')   // the name is a STABLE fingerprint — keep variable data in extras
    ->causesThe('checkout')
    ->to('blocked the order');
```

`seeViolation($name)` wraps the name in a `Shipeasy\Violation` and reports it the
same way.

## Mark expected control flow (report nothing)

When a throwable is **expected control flow** and should NOT be reported, stamp
it so any enclosing `see()` ignores it. This works even before `configure()` runs
— it only stamps the throwable:

```php
use function Shipeasy\controlFlowException;

try {
    return $cache->getOrThrow($key);
} catch (\Throwable $e) {
    controlFlowException($e)
        ->because('cache miss is normal')
        ->extras(['key' => $key]);   // local-only debug; never sent
    return rebuild($key);
}
```

`controlFlowException($e)->because($reason)` returns a tail with `->extras(...)`.

## Notes

- Reporting is best-effort and must **never raise into caller code** — every
  terminal is wrapped in `try/catch`.
- `see()` is server-key telemetry: it travels over the configured transport, no
  extra setup.
