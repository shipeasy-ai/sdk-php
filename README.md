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

## Anonymous visitors (zero-config bucketing)

For logged-out traffic you need a *stable* unit so a fractional rollout buckets
the same on the server and in the browser. Call `Identity::ensure()` once early
in your bootstrap (before any output): it reads or mints the shared
`__se_anon_id` first-party cookie (used by every Shipeasy SDK, including the
browser). Evaluations then **default to it** as `anonymous_id`, so a logged-out
request needs no per-call wiring:

```php
use Shipeasy\Identity;

Identity::ensure();                       // read or mint __se_anon_id (+ Set-Cookie)
$c->getFlag('new_checkout', []);          // buckets on the cookie automatically
```

An explicit `user_id`/`anonymous_id` always wins. Works in plain PHP, WordPress,
Laravel, Symfony, Slim — anywhere `$_COOKIE`/`setcookie()` exist (in a framework,
call it from a middleware or service provider). The cookie is non-`HttpOnly` by
design so the browser SDK buckets identically; a request with **no** unit still
resolves a fully-rolled (100%) gate as on. Cookie name + format are a cross-SDK
contract — see `18-identity-bucketing.md`.
