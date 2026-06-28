Read a flag with a user-bound `Client`. Assumes `Shipeasy\configure()` ran at
startup — see Installation.

```php
use Shipeasy\Client;

// construct once per callsite (cheap; binds the user)
$client = new Client($currentUser);

$enabled = $client->getFlag(
    '{{FLAG_KEY}}',   // gate name
    false,            // optional $default — returned ONLY when unevaluable
);                    //   (SDK not ready / flag not in blob), NOT when the gate is off
```
