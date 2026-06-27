# Overview

`shipeasy/shipeasy` is the **PHP server SDK** for Shipeasy — feature flags
(gates), dynamic configs, kill switches, A/B experiments, and i18n SSR helpers.
It targets **PHP 8.1+** and runs anywhere PHP runs: plain PHP, Laravel,
WordPress, Symfony, Slim. It is PHP-FPM friendly — `init()` fetches once per
request, with **no background poll thread**.

## Mental model: `configure()` once, `Client($user)` per request

Configure the process-wide engine **once** at startup, then bind a user per
request:

```php
use function Shipeasy\configure;
use Shipeasy\Client;

// once, at startup (server key). Optional second arg maps your user object to
// the Shipeasy attribute map; omit it when your user array IS the attribute map.
configure(getenv('SHIPEASY_SERVER_KEY'), fn ($u) => [
    'user_id' => $u->id,
    'plan'    => $u->plan,
]);

// per request — no key, no user argument on each call:
$client  = new Client($currentUser);
$enabled = $client->getFlag('new_checkout');
$cfg     = $client->getConfig('billing_copy');
$r       = $client->getExperiment('checkout_button', ['color' => 'blue']);
$panic   = $client->getKillswitch('payments_panic');
```

`new Client($user)` is **cheap**: it runs the configured `attributes` transform
once, merges the request's `__se_anon_id`, and delegates evaluation to the
single configured engine. It opens no connection and starts no poll.
Constructing a `Client` before `configure()` throws `RuntimeException`.

## Engine vs Client

There are two objects:

- **`Shipeasy\Engine`** — the heavyweight object. It holds the API key, the blob
  cache, the network fetch, `track()`, `logExposure()`, `see()`, and the
  test/offline factories (`forTesting()`, `fromFile()`, `fromSnapshot()`).
  `configure()` builds and owns exactly one. Construct it directly for full
  control (custom poll loop, `track()`, tests).
- **`Shipeasy\Client`** — the lightweight, user-bound handle. It forwards
  `getFlag`/`getFlagDetail`/`getConfig`/`getExperiment`/`getKillswitch` to the
  global engine with the user's bound attributes baked in.

> **Breaking change in 0.8.0:** the heavyweight class formerly named `Client`
> is now **`Engine`**. `Client` is the new lightweight, user-bound handle.

## Pages

- [Installation](installation.md) — composer require + requirements.
- [Configuration](configuration.md) — `configure()` in full, options, env.
- [Flags](flags.md) — `getFlag` / `getFlagDetail`.
- [Configs](configs.md) — `getConfig`.
- [Kill switches](killswitches.md) — `getKillswitch`.
- [Experiments](experiments.md) — `getExperiment` + `track`.
- [i18n](i18n.md) — SSR bootstrap + loader tag (browser does the rendering).
- [Error reporting](error-reporting.md) — `see()` / `controlFlowException()`.
- [Testing](testing.md) — `forTesting()` + override setters.
- [OpenFeature](openfeature.md) — `ShipeasyProvider`.
- [Advanced](advanced.md) — manual exposure, private attributes, sticky
  bucketing, anonymous-id bucketing, snapshots.
