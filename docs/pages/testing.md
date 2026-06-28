# Testing

In unit tests you want deterministic flag/config/experiment values with **no
network and no API key**. Configure test mode once, then read through the bound
`Client` exactly as in production.

`Shipeasy\configureForTesting()` is a drop-in sibling of `configure()` that never
fetches, never sends telemetry, and whose `track()` is a no-op. Unlike
`configure()`, the `configureFor*` siblings **replace** any prior config, so you
can reconfigure between cases.

```php
use function Shipeasy\configureForTesting;
use function Shipeasy\overrideFlag;
use function Shipeasy\clearOverrides;
use Shipeasy\Client;

// Seed values up front (no key, no network). Seed shapes:
//   flags       => ['name' => bool]
//   configs     => ['name' => value]
//   experiments => ['name' => [group, params]]
//   attributes  => optional (yourUser) => attributeMap transform
configureForTesting([
    'flags'       => ['new_checkout' => true],
    'configs'     => ['billing_copy' => ['headline' => 'Hi']],
    'experiments' => ['checkout_button' => ['treatment', ['color' => 'green']]],
]);

$client = new Client(['user_id' => 'u1']);   // construct once per callsite
$client->getFlag('new_checkout');            // true
$client->getConfig('billing_copy');          // ['headline' => 'Hi']

$r = $client->getExperiment('checkout_button', ['color' => 'blue']);
$r->inExperiment;   // true
$r->group;          // 'treatment'
$r->params;         // ['color' => 'green']  (seeded params beat defaultParams)

// track() is a no-op in test mode — safe to call, sends nothing
$client->track('purchase', ['amount' => 49]);
```

## On-the-spot overrides

The package-level override helpers layer on top of whatever
`configureForTesting()` / `configureForOffline()` set up — an override always
wins until `clearOverrides()`:

| Function | Effect |
| --- | --- |
| `Shipeasy\overrideFlag($name, bool $value)` | Force a gate's boolean value. |
| `Shipeasy\overrideConfig($name, mixed $value)` | Force a dynamic config's value. |
| `Shipeasy\overrideExperiment($name, string $group, mixed $params)` | Force an experiment assignment. |
| `Shipeasy\clearOverrides()` | Drop all on-the-spot overrides. |

```php
overrideFlag('new_checkout', true);
(new Client(['user_id' => 'u1']))->getFlag('new_checkout');   // true

clearOverrides();   // reset between cases
```

Under `configureForTesting()` there is no blob beneath, so `clearOverrides()`
reverts everything to empty-blob defaults. (`getFlagDetail()` reports
`FlagDetail::OVERRIDE` for an overridden value.)

## Offline snapshots — `configureForOffline()`

For air-gapped / reproducible CI you can evaluate the **real** rules against a
baked blob — either an in-memory `snapshot` or a JSON `path` — with no network.
Optional `flags`/`configs`/`experiments` overrides layer on top.

```php
use function Shipeasy\configureForOffline;
use Shipeasy\Client;

configureForOffline(['path' => '/etc/shipeasy/snapshot.json']);

(new Client(['user_id' => 'u1']))->getFlag('new_checkout');   // evaluated, no network
```

A complete, valid snapshot JSON for `configureForOffline(['path' => ...])`:

```json
{
  "flags": {
    "gates": {
      "new_checkout": { "enabled": true, "rules": [], "rolloutPct": 10000, "salt": "s" }
    },
    "configs": {
      "billing_copy": { "headline": "Welcome" }
    },
    "killswitches": {}
  },
  "experiments": {
    "experiments": {},
    "universes": {}
  }
}
```

A gate entry is `{"enabled":true,"rules":[],"rolloutPct":10000,"salt":"s"}`.
`rolloutPct` is in **basis points** — `10000` = 100%, `5000` = 50%. The real
evaluator runs against this snapshot (overrides apply on top); no network, no
telemetry.
