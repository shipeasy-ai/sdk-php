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

Conversion events go through the **engine** (`track()` is not on the bound
`Client`). Call it when the success action happens:

```php
$engine->track('u_123', '{{SUCCESS_EVENT}}', ['amount' => 49]);
```

- arg 1: the user id (string)
- arg 2: the event name (your experiment's success metric, e.g. `{{SUCCESS_EVENT}}`)
- arg 3: optional properties (`privateAttributes` are stripped — see [Advanced](advanced.md))

`track()` is fire-and-forget and a no-op in test/offline mode.

## Manual exposure

The PHP server is stateless and never auto-logs exposures. To get exposure
parity with the browser, call `logExposure()` at the point you actually present
the treatment — see [Advanced](advanced.md).
