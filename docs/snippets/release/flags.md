Configure once, then read a flag with a user-bound `Client`.

```php
use function Shipeasy\configure;
use Shipeasy\Client;

configure(getenv('SHIPEASY_SERVER_KEY'));

$enabled = (new Client($currentUser))->getFlag('{{RESOURCE_NAME}}');
```
