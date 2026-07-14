Report a caught, handled error (or a non-exception "violation") to Shipeasy with
`see()` — fire-and-forget, never re-throws. Package-level, so it reports against
the SDK from `Shipeasy\configure()`. Assumes `Shipeasy\configure()` ran at startup
— see Installation.

### Report a handled exception

```php
use function Shipeasy\see;

try {
    charge($order);
} catch (\Throwable $e) {
    // ->causesThe($subject)   what the error affects (e.g. "checkout"; default "app")
    // ->to($outcome)          the terminal — what you do about it; builds + fires once
    see($e)->causesThe('checkout')->to('use the backup processor');
    fallbackCharge($order);
}
```

### Attach context with `->extras(...)`

```php
use function Shipeasy\see;

try {
    charge($order);
} catch (\Throwable $e) {
    // ->extras($array)        structured fields attached to the report; call it
    //                         BEFORE ->to, or pass extras inline as ->to($outcome, $array).
    //                         (A stray ->extras AFTER ->to is ignored with a warning —
    //                         it never throws into the catch block.)
    see($e)->causesThe('checkout')->extras(['order_id' => $oid])->to('use cached prices');

    // equivalent — extras folded into the terminal, no ordering to remember:
    see($e)->causesThe('checkout')->to('use cached prices', ['order_id' => $oid]);
}
```

### Attach context from anywhere with `Shipeasy\addExtras(...)`

```php
use function Shipeasy\addExtras;
use function Shipeasy\see;

// Buffer extras earlier in the request — from any layer, not just the catch.
// Every see() report that fires LATER in the same request carries them, so you
// don't have to thread context down into the catch site. A chained ->extras /
// ->to extra of the same key wins over the ambient one.
addExtras(['order_id' => $order->id, 'tenant' => $tenant->slug]);

// ...deep in a service, later in the same request...
try {
    charge($order);
} catch (\Throwable $e) {
    // report carries order_id + tenant automatically.
    see($e)->causesThe('checkout')->to('use cached prices');
}

// PHP is share-nothing per request: under PHP-FPM / mod_php the buffer resets
// per request automatically. Under a long-running runtime (Swoole / RoadRunner /
// a resident worker loop) call Shipeasy\clearExtras() at request end.
```

### Report a non-exception violation

```php
use function Shipeasy\seeViolation;

// a bad state that isn't an exception — the name is a STABLE fingerprint; put
// variable data in ->extras(), never the name. ->to() is the terminal.
seeViolation('missing_invoice')->causesThe('billing')->to('skip the dunning email');
```

### Mark an expected exception — report NOTHING

```php
use function Shipeasy\controlFlowException;

try {
    parse($token);
} catch (\Throwable $e) {
    // transmits nothing; ->because(...) / ->extras() are local-debug only
    controlFlowException($e)->because('end of stream is expected');
}
```
