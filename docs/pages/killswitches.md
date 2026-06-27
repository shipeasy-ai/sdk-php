# Kill switches

A **kill switch** is a global panic boolean shipped in the same blob as gates and
configs. Unlike a flag it is not user-scoped — it returns the current switch
state (optionally a named per-key override).

## Bound `Client` form

```php
use Shipeasy\Client;

$client = new Client($currentUser);

$panic = $client->getKillswitch('payments_panic');     // bool
if ($panic) {
    // disable the payments path
}
```

## Named per-key override switch

A kill switch can carry named per-key override switches (the dashboard
"switches" feature). Pass the switch key as the second argument:

```php
$client->getKillswitch('payments_panic', 'eu_region');   // that named override
```

## Low-level `Engine` form

```php
$panic = $engine->getKillswitch('payments_panic');
$panic = $engine->getKillswitch('payments_panic', 'eu_region');
```

Returns a boolean; defaults to `false` when the kill switch (or the named
switch key) is not present.
