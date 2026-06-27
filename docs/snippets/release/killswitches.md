Read a kill switch (global panic boolean). Assumes `configure()` ran at startup
— see Installation.

```php
use Shipeasy\Client;

// construct once per callsite (cheap; binds the user)
$client = new Client($currentUser);

$panic = $client->getKillswitch(
    '{{RESOURCE_NAME}}',   // kill switch name
    null,                  // optional $switchKey — read a named per-key override (null = top-level value)
);
```
