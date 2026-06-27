# Installation & configuration

This page is the canonical home for installing the SDK and calling
`configure()` — every other page and snippet assumes `configure()` already ran
at startup.

## Requirements

- **PHP 8.1+**
- Extensions: `ext-json`, `ext-curl` (both required)
- Hosts: plain PHP-FPM, Laravel, Symfony, WordPress, Slim — anything PHP-FPM,
  Swoole, or RoadRunner can serve.

## Install

```bash
composer require shipeasy/shipeasy
```

The package is `shipeasy/shipeasy` on Packagist. It registers a Composer `files`
autoload, so the package-level functions (`Shipeasy\configure`, `Shipeasy\see`,
…) are available immediately after `require 'vendor/autoload.php'`:

```php
require 'vendor/autoload.php';

use function Shipeasy\configure;   // configure-once front door
use Shipeasy\Client;               // lightweight, user-bound handle
use Shipeasy\Engine;               // heavyweight engine (advanced/tests)
```

### Optional: OpenFeature

`open-feature/sdk` (`^2.0`) is an optional dependency. Install it only if you
use `Shipeasy\OpenFeature\ShipeasyProvider`:

```bash
composer require open-feature/sdk
```

## Configure once

Configure the process-wide engine **once** at startup with `Shipeasy\configure`.
It is **first-config-wins** — the first call builds and registers the engine and
fires the one-shot fetch; later calls return the existing engine.

```php
use function Shipeasy\configure;

$engine = configure(
    getenv('SHIPEASY_SERVER_KEY'),   // $apiKey — the Shipeasy SERVER key (never the client key)
    fn ($u) => [                     // $attributes — optional transform; DEFAULT is identity
        'user_id' => $u->id,         //   (omit it when your user array already IS the attribute map)
        'plan'    => $u->plan,
    ],
    [                                // $opts — optional engine options (all optional)
        'env'               => 'prod',                       // read environment for the blob
        'baseUrl'           => 'https://edge.shipeasy.dev',  // edge API base
        'disableTelemetry'  => false,                        // opt out of the usage beacon
        'telemetryUrl'      => null,                         // override telemetry endpoint
        'privateAttributes' => ['email'],                    // attrs stripped from event payloads
        'stickyStore'       => null,                         // Shipeasy\StickyBucketStore for durable bucketing
    ],
);
```

After `configure()`, bind a user per request — no key, no per-call user argument:

```php
use Shipeasy\Client;

$client  = new Client($currentUser);      // cheap; runs the attributes transform once
$enabled = $client->getFlag('new_checkout');
```

`configure()` takes the **server** key. The **public client key** (a separate
value) is only used for the i18n loader `<script>` tag, never for server
evaluation — see [i18n](i18n.md).

### Identity default

The `Client` constructor runs the `attributes` transform on your user (identity
by default — the array IS the attribute map), then merges the request's
`__se_anon_id` cookie when neither `user_id` nor `anonymous_id` was supplied, so
logged-out traffic buckets stably. An explicit `user_id`/`anonymous_id` always
wins. The SDK does not read env vars itself — pass `getenv('SHIPEASY_SERVER_KEY')`
explicitly.

### The fetch model (init/poll vs one-shot)

`configure()` fires the **one-shot fetch** immediately, so the first
`new Client($user)->getFlag(...)` resolves against real rules with no explicit
`init()`. PHP has no thread-based polling primitive, so there is **no background
poll**:

- **PHP-FPM / classic request lifecycle** — the engine fetches once per request
  (configure once per request — see PHP-FPM below). Nothing else to do.
- **Long-running runtimes (Swoole, RoadRunner, queue/CLI workers)** — call
  `$engine->init()` (alias of `initOnce()`) or `$engine->refresh()` from a
  periodic task to keep the blob fresh across requests.

---

## Framework wiring

Call `configure()` exactly **once** per process/request bootstrap. The frameworks
below differ only in *where* that single call lives.

### Laravel — service provider or bootstrap

Run `configure()` from a service provider's `boot()` (or `register()`), reading
the server key from Laravel's config/env. Bind the authenticated user per
request in your controllers via `new Client(...)`.

```php
// app/Providers/ShipeasyServiceProvider.php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use function Shipeasy\configure;

class ShipeasyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        configure(config('services.shipeasy.server_key'), fn ($u) => [
            'user_id' => $u->id,
            'plan'    => $u->plan,
        ]);
    }
}
```

Register it in `bootstrap/providers.php` (Laravel 11+) or `config/app.php`.
Then in a controller:

```php
use Shipeasy\Client;

public function checkout(\Illuminate\Http\Request $request)
{
    $client = new Client($request->user());   // construct once per request
    if ($client->getFlag('new_checkout')) {
        // …
    }
}
```

Bind `__se_anon_id` for logged-out traffic by calling `Shipeasy\Identity::ensure()`
from a middleware.

### Symfony — bundle/services bootstrap

Call `configure()` once from a kernel boot listener or a service initializer
(reading `%env(SHIPEASY_SERVER_KEY)%`), then build a `Client` per request:

```php
// e.g. from a service constructed at container boot
use function Shipeasy\configure;

configure($_ENV['SHIPEASY_SERVER_KEY'], fn ($u) => ['user_id' => $u->getId()]);
```

```php
use Shipeasy\Client;

$client  = new Client($security->getUser());   // construct once per request
$enabled = $client->getFlag('new_checkout');
```

### WordPress — plugin/theme bootstrap

Hook `configure()` onto an early action (`init` / `after_setup_theme`) and read
the key from a constant or option:

```php
add_action('init', function () {
    \Shipeasy\configure(SHIPEASY_SERVER_KEY, fn ($u) => [
        'user_id' => (string) $u->ID,
    ]);
});
```

```php
$client  = new \Shipeasy\Client(wp_get_current_user());   // construct once per request
$enabled = $client->getFlag('new_checkout');
```

### Plain PHP-FPM — init once per request

Under classic PHP-FPM each request is a fresh process state, so `configure()`
runs **once per request** at the top of your bootstrap (before any output). The
one-shot fetch then serves every evaluation in that request.

```php
require 'vendor/autoload.php';

use function Shipeasy\configure;
use Shipeasy\{Client, Identity};

configure(getenv('SHIPEASY_SERVER_KEY'));   // once per request — fires the one-shot fetch
Identity::ensure();                         // read/mint __se_anon_id for logged-out traffic

$client  = new Client($currentUser);        // construct once per callsite
$enabled = $client->getFlag('new_checkout');
```

For Swoole / RoadRunner / queue workers, configure once at worker boot and call
`$engine->refresh()` on a schedule to keep the blob fresh across requests.

## The Engine (advanced / full control)

`configure()` returns the single `Shipeasy\Engine`. Keep it when you need the
heavyweight surface (`track()`, `logExposure()`, `refresh()`, `see()`,
`forTesting()`). Constructing any `Engine` registers it as the **default**
(last-wins) used by `see()` and the user-bound `Client`; `configure()` uses
first-config-wins.

```php
use Shipeasy\Engine;

$engine = new Engine(getenv('SHIPEASY_SERVER_KEY'));
$engine->init();
$enabled = $engine->getFlag('new_checkout', ['user_id' => 'u_123']);
$engine->track('u_123', 'purchase', ['amount' => 49]);
```
