---
name: shipeasy-php
description: Use Shipeasy (feature flags, configs, kill switches, A/B experiments, i18n) from PHP. Covers configure() + Client($user), getFlag/getConfig/getKillswitch, universe()->assign(), track, testing, OpenFeature.
---

# Shipeasy PHP SDK

Server SDK for PHP 8.1+ (Laravel, Symfony, WordPress, Slim; PHP-FPM friendly —
fetches once per request, no background poll). Package: `shipeasy/shipeasy`.

> The documented surface is exactly **`Shipeasy\configure()`** (setup) and the
> bound **`new Shipeasy\Client($user)`** (use), plus the package-level functions
> below. For deeper docs, fetch any page/snippet from the manifest at
> <https://shipeasy-ai.github.io/sdk-php/manifest.json> (raw URLs below).

## Install

```bash
composer require shipeasy/shipeasy
```

## Configure once, bind a user per request

```php
require 'vendor/autoload.php';

use function Shipeasy\configure;
use Shipeasy\Client;

// Once at startup (SERVER key). Optional 2nd arg maps your user -> attribute map.
configure(getenv('SHIPEASY_SERVER_KEY'), fn ($u) => [
    'user_id' => $u->id,
    'plan'    => $u->plan,
]);

// Per request — bind the user once, call with NO user argument:
$c       = new Client($currentUser);                    // construct once per callsite
$enabled = $c->getFlag('new_checkout');                 // bool, default false
$copy    = $c->getConfig('billing_copy', ['x' => 1]);   // typed value or default
$panic   = $c->getKillswitch('payments_panic');         // bool
$d       = $c->getFlagDetail('new_checkout');           // $d->value, $d->reason
```

`configure()` is first-config-wins and fetches once per request (PHP has no
background poll). `new Client($user)` throws if called before `configure()`.

**Quiet outside production.** All egress (fetch, `track`, exposures, `see()`,
telemetry) **defaults ON in production and OFF everywhere else**, decided from
`SHIPEASY_ENV` → `APP_ENV` → `ENV` (`production`/`prod` ⇒ on), falling back to the
`env` option. So on a dev machine / CI the SDK sends nothing until you opt in —
reads still resolve overrides/defaults. Force it with the `isNetworkEnabled`
option (`true`/`false`) or set `SHIPEASY_ENV=production`.

Reference: <https://shipeasy-ai.github.io/sdk-php/pages/configuration.md> ·
<https://shipeasy-ai.github.io/sdk-php/pages/flags.md> ·
<https://shipeasy-ai.github.io/sdk-php/pages/killswitches.md>

### Laravel

Don't hand-write a provider — the package auto-discovers `ShipeasyServiceProvider`.
Run `php artisan shipeasy:install` (add `--i18n` for the client key), publish
`config/shipeasy.php`, set `SHIPEASY_SERVER_KEY` in `.env`, and the provider calls
`configure()` for you on boot. Map your user model via the `attributes` config
(an invokable class name). Place the `@shipeasyBootstrap($user)` and `@shipeasyI18n`
Blade directives in your layout `<head>`. Reference:
<https://shipeasy-ai.github.io/sdk-php/pages/installation.md>

## Experiments + track (Client-only, end to end)

Experiments are read by **universe** (a mutual-exclusion pool — the unit lands in
≤1 experiment). `assign()` is side-effect free; the exposure fires **on read** —
the first `get()` logs one deduped exposure for the enrolled unit. Pass
`exposure: false` to peek without logging.

```php
$c = new Client($currentUser);                          // construct once per callsite
$a = $c->universe('checkout')->assign();                // Shipeasy\Assignment (no exposure yet)
$color = $a->get('color', 'blue');                      // variant ?? universe default ?? fallback; first read logs the exposure
$peek  = $a->get('color', 'blue', false);               // peek — get($field, $fallback, exposure: false), no exposure
// $a->name, $a->group, $a->enrolled()

$c->track('checkout_success', ['amount' => 49]);        // conversion for the bound user
```

Reference: <https://shipeasy-ai.github.io/sdk-php/pages/experiments.md> · track
snippet <https://shipeasy-ai.github.io/sdk-php/snippets/metrics/track.md>

## Error reporting — see()

```php
use function Shipeasy\see;

try {
    chargeCard($order);
} catch (\Throwable $e) {
    see($e)->causesThe('checkout')->extras(['order_id' => $id])->to('use the backup processor');
    // extras also fold into the terminal — no ordering to remember:
    // see($e)->causesThe('checkout')->to('use the backup processor', ['order_id' => $id]);
}
```

A stray `->extras(...)` after `->to(...)` is ignored with a warning — it never
throws into the catch block. To attach context from anywhere in a request without
threading it into the catch, buffer it with `Shipeasy\addExtras(['order_id' => $id])`;
every later `see()` in the same request merges it in. **PHP is share-nothing per
request:** under PHP-FPM the buffer resets per request; under a long-running
runtime (Swoole / RoadRunner / workers) call `Shipeasy\clearExtras()` at request
end.

`Shipeasy\controlFlowException($e)->because('expected')` marks an exception as
expected (reports nothing). `Shipeasy\seeViolation('name')->...->to(...)` reports a
non-exception. Reference:
<https://shipeasy-ai.github.io/sdk-php/pages/error-reporting.md> · snippet
<https://shipeasy-ai.github.io/sdk-php/snippets/ops/see.md>

## Testing (no network, no key)

```php
use function Shipeasy\configureForTesting;
use function Shipeasy\configureForOffline;
use function Shipeasy\overrideFlag;
use function Shipeasy\clearOverrides;
use Shipeasy\Client;

// Seed values up front; reads go through the ordinary new Client($user). Replaces
// prior config, so each test can reconfigure freely.
configureForTesting([
    'flags'       => ['new_checkout' => true],
    'configs'     => ['billing_copy' => ['headline' => 'Hi']],
    'experiments' => ['checkout_button' => ['treatment', ['color' => 'green']]],
]);
$c = new Client(['user_id' => 'u_1']);
$c->getFlag('new_checkout'); // true

overrideFlag('new_checkout', false); // flip on the spot
clearOverrides();                    // drop every override (incl. the seed)

// Offline: evaluate the REAL rules from a snapshot or JSON file, no network.
configureForOffline(['path' => 'shipeasy-snapshot.json']);
```

Reference: <https://shipeasy-ai.github.io/sdk-php/pages/testing.md>

## OpenFeature

```php
use OpenFeature\OpenFeatureAPI;
use Shipeasy\OpenFeature\ShipeasyProvider;

// Assumes Shipeasy\configure(...) ran — the no-arg provider resolves it.
OpenFeatureAPI::getInstance()->setProvider(new ShipeasyProvider());
```

Install `open-feature/sdk ^2.0` (optional dep). Booleans → gates; other types →
configs. Reference: <https://shipeasy-ai.github.io/sdk-php/pages/openfeature.md>

## i18n + SSR

Server-side: emit the bootstrap + i18n loader tags (the i18n tag carries the
**public client key**); the **browser** client SDK renders `t()`.

```php
use function Shipeasy\bootstrapScriptTag;
use function Shipeasy\i18nScriptTag;

$head = bootstrapScriptTag($user, ['anonId' => $anonId])
      . i18nScriptTag($clientKey, 'en:prod');
```

Reference: <https://shipeasy-ai.github.io/sdk-php/pages/i18n.md> ·
<https://shipeasy-ai.github.io/sdk-php/pages/advanced.md>
