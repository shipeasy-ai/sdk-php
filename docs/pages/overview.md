# Overview

`shipeasy/shipeasy` is the **PHP server SDK** for Shipeasy — feature flags
(gates), dynamic configs, kill switches, A/B experiments, metric tracking,
`see()` error reporting, and i18n SSR helpers. It targets **PHP 8.1+** and runs
anywhere PHP runs: plain PHP, Laravel, Symfony, WordPress, Slim. It is PHP-FPM
friendly — `Shipeasy\configure()` fetches once per request, with **no background
poll thread**.

## Quickstart

Configure the process-wide SDK **once** at startup, then bind a user per request
with `new Shipeasy\Client($user)`:

```php
use function Shipeasy\configure;
use Shipeasy\Client;

// once, at startup — pass the SERVER key (never the client key):
configure($_ENV['SHIPEASY_SERVER_KEY']);

// per request — bind the user, then read:
$client  = new Client($currentUser);              // construct once per callsite
$enabled = $client->getFlag('new_checkout');      // bool
```

That is the whole mental model: one `configure()` at boot, one
`new Client($user)` per request, and the bound client answers every read.

## What the bound `Client` gives you

`new Client($user)` is **cheap**: it runs the configured `attributes` transform
on your user once, merges the request's `__se_anon_id` for logged-out traffic,
and then forwards every call to the configured SDK with that user baked in — so
no read takes a user argument:

```php
$client = new Client($currentUser);                // construct once per callsite

$enabled = $client->getFlag('new_checkout');                       // gate
$copy    = $client->getConfig('billing_copy');                     // dynamic config
$panic   = $client->getKillswitch('payments_panic');               // kill switch
$cta     = $client->universe('checkout')->assign();                // experiment (by universe)
$color   = $cta->get('button_color', 'red');                       // variant ?? universe default ?? fallback
$client->track('checkout_completed', ['amount' => 49]);            // metric event (auto-logs exposure on assign)
```

Constructing a `Client` before `configure()` throws `RuntimeException`.

## Package-level helpers

A handful of package-level functions cover the cases that aren't per-user reads —
all backed by the same configured SDK, so you never construct anything heavy:

- `Shipeasy\configure()` / `configureForTesting()` / `configureForOffline()` — setup.
- `Shipeasy\overrideFlag()` / `overrideConfig()` / `overrideExperiment()` / `clearOverrides()` — test overrides.
- `Shipeasy\see()` / `seeViolation()` / `controlFlowException()` — error reporting.
- `Shipeasy\bootstrapScriptTag()` / `i18nScriptTag()` — SSR script tags.
- `Shipeasy\onChange()` — change listener (long-running runtimes only).

## Pages

- [Installation](installation.md) — `composer require` + per-framework wiring + the full `configure()` reference.
- [Configuration](configuration.md) — the `configure()` options in detail.
- [Flags](flags.md) — `getFlag` / `getFlagDetail`.
- [Configs](configs.md) — `getConfig`.
- [Kill switches](killswitches.md) — `getKillswitch`.
- [Experiments](experiments.md) — `universe()->assign()` + `track`.
- [i18n](i18n.md) — SSR bootstrap + loader tag (browser does the rendering).
- [Error reporting](error-reporting.md) — `see()` / `controlFlowException()`.
- [Testing](testing.md) — `configureForTesting()` / `configureForOffline()` + overrides.
- [OpenFeature](openfeature.md) — `ShipeasyProvider`.
- [Advanced](advanced.md) — manual exposure, private attributes, sticky bucketing, anonymous-id bucketing, snapshots.
