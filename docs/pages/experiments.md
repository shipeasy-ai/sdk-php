# Experiments

An **experiment** assigns the user to a group and returns that group's
parameters. Use it to read the variant, then `track()` the conversion event.

## Reading the assignment

`getExperiment` returns a `Shipeasy\ExperimentResult`:

```php
public bool   $inExperiment;   // was the user enrolled?
public string $group;          // the assigned group name (e.g. 'control')
public mixed  $params;         // the group's params (your defaultParams if not enrolled)
```

### Bound `Client` form

`defaultParams` is **required** — it is returned when the user is not enrolled:

```php
use Shipeasy\Client;

$client = new Client($currentUser);
$r = $client->getExperiment('checkout_button', ['color' => 'blue']);

if ($r->inExperiment) {
    $color = $r->params['color'];   // the assigned variant
} else {
    $color = 'blue';                // your default
}
```

### Low-level `Engine` form

```php
$r = $engine->getExperiment('checkout_button', ['user_id' => 'u_123'], ['color' => 'blue']);
$r->inExperiment;   // bool
$r->group;          // string
$r->params;         // mixed
```

## Tracking conversions

You already have a bound `Client` for `getExperiment` — record the conversion on
that **same** `Client`. No user argument: the unit is derived from the bound
attribute map (`user_id`, else `anonymous_id`). Call it when the success action
happens:

```php
$client = new Client($currentUser);
$r = $client->getExperiment('checkout_button', ['color' => 'blue']);

// …present the variant, then on conversion:
$client->track('{{SUCCESS_EVENT}}', ['amount' => 49]);
```

- arg 1: the event name (your experiment's success metric, e.g. `{{SUCCESS_EVENT}}`)
- arg 2: optional properties (`privateAttributes` are stripped — see [Advanced](advanced.md))

`track()` is fire-and-forget and a no-op in test/offline mode.

### Low-level `Engine` form

For advanced use you can drop down to the engine and pass the user id explicitly:

```php
$engine->track('u_123', '{{SUCCESS_EVENT}}', ['amount' => 49]);
```

## Manual exposure

The PHP server is stateless and never auto-logs exposures. To get exposure
parity with the browser, call `logExposure()` on the bound `Client` at the point
you actually present the treatment:

```php
$client = new Client($currentUser);
$r = $client->getExperiment('checkout_button', ['color' => 'blue']);
$client->logExposure('checkout_button');   // emits one exposure if enrolled
```

The low-level `Engine::logExposure($user, $experiment)` form remains for advanced
use — see [Advanced](advanced.md).
