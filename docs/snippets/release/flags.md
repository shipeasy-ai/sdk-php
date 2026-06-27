Read a flag with a user-bound `Client`. Assumes `configure()` ran at startup —
see Installation.

```php
use Shipeasy\Client;

// construct once per callsite (cheap; binds the user)
$client = new Client($currentUser);

$enabled = $client->getFlag(
    '{{RESOURCE_NAME}}',   // gate name
    false,                 // optional $default — returned ONLY when unevaluable
);                         //   (client not ready / flag not in blob), NOT when the gate is off
```
