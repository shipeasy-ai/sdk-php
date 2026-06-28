# Kill switches

A **kill switch** is a global panic boolean shipped in the same blob as gates and
configs. Unlike a flag it is not user-scoped — it returns the current switch
state (optionally a named per-key override).

## Read a kill switch

```php
use Shipeasy\Client;

$client = new Client($currentUser);   // construct once per callsite

$panic = $client->getKillswitch('payments_panic');     // bool
if ($panic) {
    // disable the payments path
}
```

Assumes `Shipeasy\configure()` ran at startup — see [Installation](installation.md).

## Named per-key override switch

A kill switch can carry named per-key override switches (the dashboard
"switches" feature). Pass the switch key as the **second** argument — the
`$switchKey` — to read that named override:

```php
$client = new Client($currentUser);                      // construct once per callsite
$region = 'eu_region';
$panic  = $client->getKillswitch('payments_panic', $region);   // $switchKey = 'eu_region'
```

When the named `$switchKey` has no configured override, the call falls back to
the kill switch's top-level `killed` value.

## Default behaviour

Returns a boolean; defaults to `false` when the kill switch is not present in the
fetched blob.
