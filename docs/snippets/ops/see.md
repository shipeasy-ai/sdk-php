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
    // ->extras($array)        structured fields attached to the report (local-only debug)
    see($e)->causesThe('checkout')->extras(['order_id' => $oid])->to('use cached prices');
}
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
