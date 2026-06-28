Read a kill switch (global panic boolean). Assumes `Shipeasy\configure()` ran at
startup — see Installation.

```php
use Shipeasy\Client;

// construct once per callsite (cheap; binds the user)
$client = new Client($currentUser);

$panic = $client->getKillswitch(
    '{{KILLSWITCH_KEY}}',   // kill switch name
    null,                   // optional $switchKey — read a named per-key override
);                          //   (null = top-level value; unconfigured key falls back to it too)
```
