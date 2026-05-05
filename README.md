# shipeasy/shipeasy (PHP)

Server SDK for [Shipeasy](https://shipeasy.dev). Compatible with PHP 8.1+, including Laravel and WordPress hosts. PHP-FPM friendly: `init()` fetches once per request — no background thread.

```bash
composer require shipeasy/shipeasy
```

```php
use Shipeasy\Client;

$c = new Client(getenv('SHIPEASY_SERVER_KEY'));
$c->init();

$enabled = $c->getFlag('new_checkout', ['user_id' => 'u_123']);
$cfg = $c->getConfig('billing_copy');
$r = $c->getExperiment('checkout_button', ['user_id' => 'u_123'], ['color' => 'blue']);
$c->track('u_123', 'purchase', ['amount' => 49]);
```

For long-running runtimes (Swoole, RoadRunner, queue workers) call `$c->init()` from a periodic task — PHP has no thread-based polling primitive.
