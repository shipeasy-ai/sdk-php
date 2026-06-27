Read a kill switch (global panic boolean).

```php
use Shipeasy\Client;

$panic = (new Client($currentUser))->getKillswitch('{{RESOURCE_NAME}}');
```
