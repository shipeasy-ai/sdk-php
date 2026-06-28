# Installation & configuration

This page is the canonical home for installing the SDK and calling
`Shipeasy\configure()` — every other page and snippet assumes `configure()`
already ran at startup.

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
```

### Optional: OpenFeature

`open-feature/sdk` (`^2.0`) is an optional dependency. Install it only if you
use `Shipeasy\OpenFeature\ShipeasyProvider` — see [OpenFeature](openfeature.md):

```bash
composer require open-feature/sdk
```

## Configure once

Configure the SDK **once** at startup with `Shipeasy\configure`. It is
**first-config-wins** — the first call sets up the SDK and fires the one-shot
fetch; later calls are ignored.

```php
use function Shipeasy\configure;

configure(
    $_ENV['SHIPEASY_SERVER_KEY'],   // $apiKey — the Shipeasy SERVER key (never the client key)
    fn ($u) => [                    // $attributes — optional transform; DEFAULT is identity
        'user_id' => $u->id,        //   (omit it when your user array already IS the attribute map)
        'plan'    => $u->plan,
    ],
    [                               // $opts — optional configure() options (all optional)
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

$client  = new Client($currentUser);      // construct once per callsite; runs the attributes transform
$enabled = $client->getFlag('new_checkout');
```

### `configure()` arguments

| Arg | Type | Notes |
| --- | --- | --- |
| `$apiKey` | `string` | The Shipeasy **server** key. Required. |
| `$attributes` | `callable\|null` | `(yourUser) => attributeMap`. **Default is identity** — omit it when your user array already IS the Shipeasy attribute map. Runs once per `new Client($user)`. |
| `$opts` | `array` | The options below (all optional). |

### `$opts` keys

| Key | Default | Meaning |
| --- | --- | --- |
| `env` | `'prod'` | The read environment for the blob. |
| `baseUrl` | `https://edge.shipeasy.dev` | Edge API base. |
| `disableTelemetry` | `false` | Opt out of the usage-telemetry beacon. |
| `telemetryUrl` | (built-in) | Override the telemetry endpoint. |
| `privateAttributes` | `[]` | Attribute names stripped from outbound event payloads (LD/Statsig `privateAttributes`). See [Advanced](advanced.md). |
| `stickyStore` | `null` | A `Shipeasy\StickyBucketStore` for durable experiment bucketing. See [Advanced](advanced.md). |

The **public client key** (a separate value) is *not* passed to `configure()`.
It is only used for the i18n loader `<script>` tag — see [i18n](i18n.md).

### Identity default

The `Client` constructor runs the `attributes` transform on your user (identity
by default — the array IS the attribute map), then merges the request's
`__se_anon_id` cookie when neither `user_id` nor `anonymous_id` was supplied, so
logged-out traffic buckets stably. An explicit `user_id`/`anonymous_id` always
wins. The SDK does not read env vars itself — pass `$_ENV['SHIPEASY_SERVER_KEY']`
explicitly.

### The fetch model (no background poll)

`configure()` fetches the rule blob **once per request** the moment it runs, so
the first `new Client($user)->getFlag(...)` resolves against real rules with no
explicit init step. PHP is request-scoped and has **no background poll thread**:

- **PHP-FPM / classic request lifecycle** — `configure()` fetches once per
  request. Nothing else to do.
- **Long-running runtimes (Swoole, RoadRunner, queue/CLI workers)** — a process
  outlives many requests, so refresh the blob on a schedule (e.g. from a periodic
  task at your chosen interval) to keep it fresh across requests.

---

## Framework wiring

Call `configure()` exactly **once** per process/request bootstrap. The frameworks
below differ only in *where* that single call lives.

### Laravel — service provider

Run `configure()` from a service provider's `boot()`, reading the server key from
Laravel's config/env. Bind the authenticated user per request in controllers.

```bash
composer require shipeasy/shipeasy
```

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
from a middleware — see [Advanced](advanced.md).

### Symfony — services bootstrap

Call `configure()` once from a kernel boot listener or a service initializer
(reading `%env(SHIPEASY_SERVER_KEY)%`), then build a `Client` per request:

```bash
composer require shipeasy/shipeasy
```

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

```bash
composer require shipeasy/shipeasy
```

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

### Plain PHP / PHP-FPM — configure once per request

Under classic PHP-FPM each request is a fresh process state, so `configure()`
runs **once per request** at the top of your bootstrap (before any output). The
one-shot fetch then serves every evaluation in that request.

```bash
composer require shipeasy/shipeasy
```

```php
require 'vendor/autoload.php';

use function Shipeasy\configure;
use Shipeasy\{Client, Identity};

configure($_ENV['SHIPEASY_SERVER_KEY']);   // once per request — fetches the blob
Identity::ensure();                        // read/mint __se_anon_id for logged-out traffic

$client  = new Client($currentUser);       // construct once per callsite
$enabled = $client->getFlag('new_checkout');
```

For Swoole / RoadRunner / queue workers, configure once at worker boot and
refresh the blob on a schedule to keep it fresh across requests.
