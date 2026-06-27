Read a dynamic config value (with a fallback for the absent case). Assumes
`configure()` ran at startup — see Installation.

```php
use Shipeasy\Client;

// construct once per callsite (cheap; binds the user)
$client = new Client($currentUser);

$value = $client->getConfig(
    '{{RESOURCE_NAME}}',              // config name
    ['headline' => 'Welcome'],       // optional $default — returned when the config key is absent
);
```
