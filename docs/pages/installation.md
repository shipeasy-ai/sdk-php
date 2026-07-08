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
        'logLevel'          => 'warn',                       // SDK diagnostics: silent|error|warn|info|debug
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
| `logLevel` | `'warn'` | SDK diagnostic verbosity: `'silent'`, `'error'`, `'warn'`, `'info'`, `'debug'`. Runtime reads are fail-safe (never throw) and log at this level. See [Configuration](configuration.md). |

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

### Laravel — `php artisan shipeasy:install`

The package ships a Laravel service provider (auto-discovered — no manual
registration). Install in four steps:

```bash
# 1. Require the package — auto-discovery registers ShipeasyServiceProvider.
composer require shipeasy/shipeasy

# 2. Publish config/shipeasy.php and seed the .env keys. Add --i18n to also
#    seed SHIPEASY_CLIENT_KEY and surface the @shipeasyI18n directive.
php artisan shipeasy:install
```

```dotenv
# 3. Set your keys in .env (minted at https://app.shipeasy.ai → Settings → SDK keys):
SHIPEASY_SERVER_KEY=...          # server-side secret — NEVER sent to the browser
SHIPEASY_CLIENT_KEY=...          # public client key — only used by @shipeasyI18n (with --i18n)
```

Once `SHIPEASY_SERVER_KEY` is set the provider calls `Shipeasy\configure()` for
you on boot (reading `config/shipeasy.php`) — you never call `configure()`
yourself. Then bind the authenticated user per request in a controller:

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

```blade
{{-- 4. Place the layout helpers in your Blade <head>
       (e.g. resources/views/layouts/app.blade.php): --}}
<head>
    @shipeasyBootstrap($user)   {{-- SSR flags/experiments bootstrap tag --}}
    @shipeasyI18n               {{-- i18n loader tag (with --i18n; reads config) --}}
    {{-- … --}}
</head>
```

`@shipeasyBootstrap($user)` echoes `Shipeasy\bootstrapScriptTag($user)` and
`@shipeasyI18n` echoes `Shipeasy\i18nScriptTag(config('shipeasy.client_key'),
config('shipeasy.i18n_profile'))`. Following the Laravel convention, the install
command does **not** edit your layout — it tells you where to place the
directives.

#### Mapping your user model

To map your user model to the Shipeasy attribute map, set `attributes` in
`config/shipeasy.php` to an **invokable** class name (resolved from the
container); leave it `null` for the identity default:

```php
// config/shipeasy.php
'attributes' => \App\Shipeasy\ShipeasyAttributes::class,

// app/Shipeasy/ShipeasyAttributes.php
final class ShipeasyAttributes
{
    public function __invoke($user): array
    {
        return ['user_id' => (string) $user->id, 'plan' => $user->plan];
    }
}
```

Bind `__se_anon_id` for logged-out traffic by calling `Shipeasy\Identity::ensure()`
from a middleware — see [Advanced](advanced.md).

#### Manual fallback (no auto-discovery)

If you have disabled package auto-discovery, register the provider by hand in
`bootstrap/providers.php` (Laravel 11+) or `config/app.php`:

```php
// bootstrap/providers.php
return [
    // …
    Shipeasy\Laravel\ShipeasyServiceProvider::class,
];
```

Or skip the package provider entirely and call `configure()` from your own
provider's `boot()`:

```php
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
