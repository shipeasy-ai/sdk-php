Read an experiment assignment, then track the conversion on the same bound `Client`.

```php
use function Shipeasy\configure;
use Shipeasy\Client;

configure(getenv('SHIPEASY_SERVER_KEY'));

$client = new Client($currentUser);
$r = $client->getExperiment('{{RESOURCE_NAME}}', ['color' => 'blue']);
$color = $r->inExperiment ? $r->params['color'] : 'blue';

$client->track('{{SUCCESS_EVENT}}', ['amount' => 49]);
```
