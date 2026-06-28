Track a metric/conversion event from the bound `Client`. Metrics in the dashboard
are computed from these events. Assumes `Shipeasy\configure()` ran at startup —
see Installation.

### Track an event

```php
use Shipeasy\Client;

// construct once per callsite (cheap; binds the user)
$client = new Client($currentUser);

// track($event, $props = [])
//   $event — the event your metric is built on (required)
//   $props — optional payload; numeric/string fields you can sum/filter on
//            in a metric (private attributes are stripped before egress)
$client->track('{{EVENT_NAME}}', ['amount' => 49, 'currency' => 'usd']);
```

Fire-and-forget (never blocks your response) and a no-op under
`Shipeasy\configureForTesting()` / `Shipeasy\configureForOffline()`. The unit is
the bound user (`user_id`, else `anonymous_id`); with no unit the call is a no-op.

### Track without properties

```php
use Shipeasy\Client;

// construct once per callsite
$client = new Client($currentUser);

$client->track('{{EVENT_NAME}}');   // $props are optional
```
