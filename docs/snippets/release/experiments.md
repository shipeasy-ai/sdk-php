Assign a unit within a universe (a mutual-exclusion pool — the unit lands in ≤1
experiment), read the assigned params, then record the conversion event on the
same bound `Client`. Assumes `Shipeasy\configure()` ran at startup — see
Installation.

```php
use Shipeasy\Client;

// construct once per callsite (cheap; binds the user + runs the attributes transform)
$client = new Client($currentUser);

// universe($name)->assign() → Shipeasy\Assignment
//   $name — the UNIVERSE name (not an experiment); the unit lands in ≤1 experiment
//   ->name       — the experiment the unit landed in, or null when not enrolled
//   ->group      — the assigned variant, or null when not enrolled
//   ->enrolled() — bool (=== group !== null)
//   ->get($field, $fallback) — variant override ?? universe default ?? $fallback
$exp = $client->universe('{{EXPERIMENT_KEY}}')->assign();

echo $exp->get('primary_label', 'Sign up'); // always safe — falls back when not enrolled

// On conversion — track() is on the same bound Client; the unit is inferred from
// the bound user (user_id, else anonymous_id), so there is no userId argument:
//   track($eventName, $props?)
//     $eventName — the success event name
//     $props     — optional metric properties (private attrs are stripped)
$client->track(
    '{{SUCCESS_EVENT}}',     // event name (the experiment's success metric)
    ['group' => $exp->group],// optional $props (default [])
);
```
