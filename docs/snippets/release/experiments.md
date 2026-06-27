Read an experiment assignment, then track the conversion via the engine.

```php
use function Shipeasy\configure;
use Shipeasy\Client;

$engine = configure(getenv('SHIPEASY_SERVER_KEY'));

$r = (new Client($currentUser))->getExperiment('{{RESOURCE_NAME}}', ['color' => 'blue']);
$color = $r->inExperiment ? $r->params['color'] : 'blue';

$engine->track('u_123', '{{SUCCESS_EVENT}}', ['amount' => 49]);
```
