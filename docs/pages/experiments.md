# Experiments (`universe()->assign()` + `track`)

Experiments are read by **universe**. A universe is a mutual-exclusion pool: a
unit lands in **at most one** experiment in it. `assign()` picks that experiment
(if any), returns the assigned group plus its resolved parameters, and auto-logs
a single (deduped) exposure. You read parameters with `assign()->get($field,
$fallback)` and record a conversion with `track()` — both on the **same** bound
`Client`.

## Read an experiment

Ask the **universe**, not the experiment: the unit lands in ≤1 experiment in it.
Because the user is bound at construction, `assign()` takes no argument.

```php
use Shipeasy\Client;

$client = new Client($currentUser);            // construct once per callsite

// Ask the UNIVERSE, not the experiment.
$cta = $client->universe('hero_cta')->assign();

// Read a param: variant override ?? universe default ?? your fallback.
echo $cta->get('primary_label', 'Sign up');
```

Assumes `Shipeasy\configure()` ran at startup — see [Installation](installation.md).

## `Assignment`

`assign()` returns a `Shipeasy\Assignment` (it never throws):

```php
$cta->name;                 // ?string — the experiment landed in, or null when not enrolled
$cta->group;                // ?string — the assigned variant, or null when not enrolled
$cta->enrolled();           // bool    — === ($cta->group !== null)
$cta->get('field', $fb);    // mixed   — variant override ?? universe default ?? $fb
```

When the unit isn't enrolled (targeting/holdout/allocation), `enrolled()` is
`false`, `group` and `name` are `null`, and `get($field, $fallback)` returns the
universe default if there is one, else your `$fallback` — so reading a param is
always safe.

```php
$cta = $client->universe('hero_cta')->assign();
if ($cta->enrolled()) {
    // $cta->group is the variant, e.g. 'treatment'
}
$label = $cta->get('primary_label', 'Sign up');   // never throws
```

## Track conversions

Record the success event so the analysis pipeline can compute lift. Call `track`
on the **same** bound `Client` — no user argument, the unit is derived from the
bound attribute map (`user_id`, else `anonymous_id`):

```php
$client->track(
    'checkout_completed',    // the event name (your experiment's success metric)
    ['amount' => 49],        // optional $props — event properties (default [])
);
```

`track()` is fire-and-forget and a no-op in test/offline mode. See the
[metrics/track](../snippets/metrics/track.md) snippet for the standalone form.

## Iterating over many users

When you don't have a single bound user — e.g. a batch job scoring many users —
construct a fresh `Client` per user inside the loop. It's cheap (it delegates to
the configuration built once at startup; it opens no connection):

```php
foreach ($users as $user) {
    $client = new Client($user);              // construct once per user (cheap)
    $cta    = $client->universe('hero_cta')->assign();
    $client->track('checkout_completed', ['group' => $cta->group]);
}
```

## Exposure logging

By default `assign()` auto-logs a single (deduped) exposure to `/collect` when
the unit is enrolled. The server dedups per process, so repeated `assign()` calls
for the same unit/experiment/group emit one exposure. There is no manual
`logExposure` primitive anymore — reading *is* the exposure. See
[Advanced](advanced.md) for the private-attribute and sticky-bucketing notes.
