# Error reporting — `see()`

This SDK ships the `see()` error-reporting surface (parity with the TS SDK).
Use it to report a **handled** throwable (or a non-exception "violation") to
Shipeasy as fire-and-forget telemetry, while you keep your normal control flow.

## Report a caught throwable

The package-level `Shipeasy\see()` targets the default engine (the
configured / last-constructed `Engine`). It NEVER throws — before any engine
exists it logs a warning and returns a no-op chain.

```php
use function Shipeasy\see;

try {
    chargeCard($order);
} catch (\Throwable $e) {
    see($e)
        ->causesThe('checkout')        // the subject (default: 'app')
        ->extras(['order_id' => $id])  // local debug context (never transmitted)
        ->to('failed to charge');      // terminal: the consequence (default: 'hit an error')

    return back()->withError('Payment failed');
}
```

The chain is `see($problem)->causesThe($subject)->extras($extras)->to($outcome)`:

- `causesThe(string $subject)` — what the error affected (default `'app'`).
- `extras(array $extras)` — debug context stored locally only, **never sent**.
- `to(string $outcome)` — **terminal**; builds the event and fire-and-forgets the
  report. Idempotent (a second `to()` is a no-op). The default outcome is
  `'hit an error'`.

Target a specific engine with `$engine->see($problem)` instead of the
package-level function.

## Report a non-exception violation

```php
use function Shipeasy\seeViolation;

seeViolation('cart_total_mismatch')
    ->causesThe('checkout')
    ->to('blocked the order');
```

`seeViolation($name)` wraps the name in a `Shipeasy\Violation` and reports it the
same way. There is also `$engine->seeViolation($name)`.

## Mark expected control flow (report nothing)

When a throwable is **expected control flow** and should NOT be reported, stamp
it so any enclosing `see()` ignores it. This works even before an engine exists —
it only stamps the throwable:

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
- `see()` is server-key telemetry: it travels over the engine's configured
  transport, no extra setup.
