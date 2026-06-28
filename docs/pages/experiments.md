# Experiments

An **experiment** assigns the user to a group and returns that group's
parameters. Use it to read the variant, then `track()` the conversion event —
both on the **same** bound `Client`.

## Reading the assignment

`getExperiment` returns a `Shipeasy\ExperimentResult`:

```php
public bool   $inExperiment;   // was the user enrolled?
public string $group;          // the assigned group name (e.g. 'control')
public mixed  $params;         // the group's params (your defaultParams if not enrolled)
```

`defaultParams` is **required** — it is returned when the user is not enrolled:

```php
use Shipeasy\Client;

$client = new Client($currentUser);   // construct once per callsite
$r      = $client->getExperiment(
    'checkout_button',         // experiment name
    ['color' => 'blue'],       // $defaultParams — returned when the user isn't enrolled
);

if ($r->inExperiment) {
    $color = $r->params['color'];   // the assigned variant
} else {
    $color = 'blue';                // your default
}
```

Assumes `Shipeasy\configure()` ran at startup — see [Installation](installation.md).

## Tracking conversions

Record the conversion on the **same** `Client` you read the assignment from. No
user argument: the unit is derived from the bound attribute map (`user_id`, else
`anonymous_id`). Call it when the success action happens:

```php
$client = new Client($currentUser);   // construct once per callsite
$r      = $client->getExperiment('checkout_button', ['color' => 'blue']);

// …present the variant, then on conversion:
$client->track(
    'checkout_completed',   // the event name (your experiment's success metric)
    ['amount' => 49],       // optional $props — event properties (default [])
);
```

- arg 1: the event name (your experiment's success metric).
- arg 2: optional properties (`privateAttributes` are stripped — see [Advanced](advanced.md)).

`track()` is fire-and-forget and a no-op in test/offline mode. See the
[metrics/track](../snippets/metrics/track.md) snippet for the standalone form.

## Manual exposure

The PHP server is stateless and never auto-logs exposures. To get exposure
parity with the browser, call `logExposure()` on the bound `Client` at the point
you actually present the treatment:

```php
$client = new Client($currentUser);   // construct once per callsite
$r      = $client->getExperiment('checkout_button', ['color' => 'blue']);
$client->logExposure('checkout_button');   // emits one exposure if enrolled
```

See [Advanced](advanced.md) for more on manual exposure.
