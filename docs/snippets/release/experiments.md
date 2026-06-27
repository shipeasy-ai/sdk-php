Read an experiment assignment, then track the conversion on the same bound
`Client`. Assumes `configure()` ran at startup — see Installation.

```php
use Shipeasy\Client;

// construct once per callsite (cheap; binds the user)
$client = new Client($currentUser);

$r = $client->getExperiment(
    '{{RESOURCE_NAME}}',     // experiment name
    ['color' => 'blue'],     // $defaultParams — params returned when the user isn't enrolled
);
$color = $r->inExperiment ? $r->params['color'] : 'blue';

// track() is on the bound Client (NOT the Engine): the unit comes from the
// bound user — no userId argument.
$client->track(
    '{{SUCCESS_EVENT}}',     // event name
    ['amount' => 49],        // optional $props — event properties (default [])
);
```
