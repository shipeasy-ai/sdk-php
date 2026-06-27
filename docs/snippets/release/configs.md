Read a dynamic config value (with a fallback for the absent case).

```php
use Shipeasy\Client;

$value = (new Client($currentUser))
    ->getConfig('{{RESOURCE_NAME}}', ['headline' => 'Welcome']);
```
