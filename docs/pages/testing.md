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
//   experiments => ['name' => [group, params]]  (refines an experiment that
//                  exists in a universe — see "Asserting an experiment" below)
//   attributes  => optional (yourUser) => attributeMap transform
configureForTesting([
    'flags'   => ['new_checkout' => true],
    'configs' => ['billing_copy' => ['headline' => 'Hi']],
]);

$client = new Client(['user_id' => 'u1']);   // construct once per callsite
$client->getFlag('new_checkout');            // true
$client->getConfig('billing_copy');          // ['headline' => 'Hi']

// track() is a no-op in test mode — safe to call, sends nothing
$client->track('purchase', ['amount' => 49]);
```

## Asserting an experiment

Experiments are read by **universe**. An `experiments` seed (and
`overrideExperiment`) **refines** an experiment that already lives in a universe —
it forces that experiment's variant. It does *not* invent an experiment in an
empty universe, and it is read by universe, not by experiment name. Seed the
universe + experiment via `configureForOffline()`, then force the variant:

```php
use function Shipeasy\configureForOffline;
use Shipeasy\Client;

configureForOffline([
    'snapshot' => [
        'flags' => ['gates' => [], 'configs' => [], 'killswitches' => []],
        'experiments' => [
            'universes' => ['checkout' => ['holdout_range' => null]],
            'experiments' => [
                'checkout_button' => [
                    'universe'      => 'checkout',
                    'allocationPct' => 10000,
                    'salt'          => 's',
                    'status'        => 'running',
                    'groups'        => [['name' => 'control', 'weight' => 10000, 'params' => ['color' => 'blue']]],
                ],
            ],
        ],
    ],
    'experiments' => ['checkout_button' => ['treatment', ['color' => 'green']]],
]);

$a = (new Client(['user_id' => 'u1']))->universe('checkout')->assign();
$a->enrolled();          // true
$a->group;               // 'treatment'  (forced by the override)
$a->get('color');        // 'green'       (override params beat the universe default)
```

On an empty test-mode blob (no snapshot) `universe($name)->assign()` returns a
safe not-enrolled `Assignment` (`group === null`), and `get($field, $fallback)`
resolves to the universe default, else your `$fallback`.

## On-the-spot overrides

The package-level override helpers layer on top of whatever
`configureForTesting()` / `configureForOffline()` set up — an override always
wins until `clearOverrides()`:

| Function | Effect |
| --- | --- |
| `Shipeasy\overrideFlag($name, bool $value)` | Force a gate's boolean value. |
| `Shipeasy\overrideConfig($name, mixed $value)` | Force a dynamic config's value. |
| `Shipeasy\overrideExperiment($name, string $group, mixed $params)` | Force an experiment's variant. Surfaces through `universe($name)->assign()` when the experiment exists in the loaded blob. |
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
