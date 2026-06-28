Read an experiment assignment, then track the conversion on the same bound
`Client`. Assumes `Shipeasy\configure()` ran at startup — see Installation.

```php
use Shipeasy\Client;

// construct once per callsite (cheap; binds the user)
$client = new Client($currentUser);

$r = $client->getExperiment(
    '{{EXPERIMENT_KEY}}',    // experiment name
    ['color' => 'blue'],     // $defaultParams — params returned when the user isn't enrolled
);
$color = $r->inExperiment ? $r->params['color'] : 'blue';

// track() is on the same bound Client — the unit comes from the bound user, so
// there is no userId argument.
$client->track(
    '{{SUCCESS_EVENT}}',     // event name (the experiment's success metric)
    ['amount' => 49],        // optional $props — event properties (default [])
);
```
