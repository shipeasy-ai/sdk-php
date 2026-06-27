# Testing

In unit tests you want deterministic flag/config/experiment values with **no
network and no API key**. `Engine::forTesting()` builds an engine that never
fetches (`init()`/`initOnce()` are no-ops), never sends telemetry, and whose
`track()` is a no-op. Seed each entity with the override setters — an override
always wins over the fetched blob.

```php
use Shipeasy\Engine;

$c = Engine::forTesting();   // no key, no network

// Flags
$c->overrideFlag('new_checkout', true);
$c->getFlag('new_checkout', ['user_id' => 'u1']);        // true

// Configs
$c->overrideConfig('billing_copy', ['headline' => 'Hi']);
$c->getConfig('billing_copy');                            // ['headline' => 'Hi']

// Experiments — returns an inExperiment result with your group + params
$c->overrideExperiment('checkout_button', 'treatment', ['color' => 'green']);
$r = $c->getExperiment('checkout_button', ['user_id' => 'u1'], ['color' => 'blue']);
$r->inExperiment;   // true
$r->group;          // 'treatment'
$r->params;         // ['color' => 'green']  (override params beat defaultParams)

// track() is a no-op in test mode — safe to call, sends nothing
$c->track('u1', 'purchase', ['amount' => 49]);

// Reset between cases
$c->clearOverrides();
```

## Override setters

| Method | Effect |
| --- | --- |
| `overrideFlag($name, bool $value)` | Force a gate's boolean value. |
| `overrideConfig($name, mixed $value)` | Force a dynamic config's value. |
| `overrideExperiment($name, string $group, mixed $params)` | Force an experiment assignment. |
| `clearOverrides()` | Drop all overrides. |

The override setters also work on a normal `new Engine(...)` instance — an
overridden key short-circuits before any network read. (`getFlagDetail()` reports
`FlagDetail::OVERRIDE` for an overridden value.)

## Binding the test engine to `Client`

`Engine::forTesting()` registers itself as the default engine (last-wins), so the
user-bound `Client` resolves against your overrides:

```php
$c = Engine::forTesting();
$c->overrideFlag('new_checkout', true);

(new \Shipeasy\Client(['user_id' => 'u1']))->getFlag('new_checkout');   // true
```

Use `Engine::resetForTesting()` to clear the registered default between suites.

## Offline snapshots

For air-gapped / reproducible CI you can build an engine from a baked blob — see
[Advanced](advanced.md): `Engine::fromFile()` / `Engine::fromSnapshot()`. The
**real** eval runs against the snapshot (overrides apply on top), with no
network and telemetry off.
