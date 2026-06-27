---
name: shipeasy-php
description: Use Shipeasy (feature flags, configs, kill switches, A/B experiments, i18n) from PHP. Covers configure() + Client($user), getFlag/getConfig/getExperiment/getKillswitch, track, testing, OpenFeature.
---

# Shipeasy PHP SDK

Server SDK for PHP 8.1+ (Laravel, WordPress, Symfony, Slim; PHP-FPM friendly —
fetches once per request, no background poll). Package: `shipeasy/shipeasy`.

## Install

```bash
composer require shipeasy/shipeasy
```

## Configure once, bind a user per request

```php
require 'vendor/autoload.php';

use function Shipeasy\configure;
use Shipeasy\Client;

// once at startup (SERVER key). Optional 2nd arg maps your user -> attribute map.
configure(getenv('SHIPEASY_SERVER_KEY'), fn ($u) => [
    'user_id' => $u->id,
    'plan'    => $u->plan,
]);

// per request — no key, no per-call user argument:
$c       = new Client($currentUser);
$enabled = $c->getFlag('new_checkout');                 // bool, default false
$copy    = $c->getConfig('billing_copy', ['x' => 1]);   // typed value or default
$panic   = $c->getKillswitch('payments_panic');         // bool
$r       = $c->getExperiment('checkout_button', ['color' => 'blue']);  // ExperimentResult
```

`new Client($user)` is cheap and delegates to the single configured engine.
Constructing it before `configure()` throws.

## Engine (advanced: track, custom poll, tests)

```php
use Shipeasy\Engine;

$engine = new Engine(getenv('SHIPEASY_SERVER_KEY'));
$engine->init();   // one-shot fetch (alias of initOnce)
$on = $engine->getFlag('new_checkout', ['user_id' => 'u1']);
$engine->track('u1', 'purchase', ['amount' => 49]);   // conversion event
```

> 0.8.0 rename: the old heavyweight `Client` is now `Engine`; `Client` is the new
> lightweight user-bound handle.

## Experiments + tracking

```php
$r = $c->getExperiment('checkout_button', ['color' => 'blue']);
$color = $r->inExperiment ? $r->params['color'] : 'blue';   // $r->inExperiment, $r->group, $r->params
$engine->track('u1', 'checkout_success', ['amount' => 49]);
```

Server is stateless — call `$engine->logExposure('u1', 'checkout_button')` at the
treatment point for exposure parity.

## Evaluation detail

```php
$d = $c->getFlagDetail('new_checkout');
$d->value;   // bool
$d->reason;  // FlagDetail::RULE_MATCH | OFF | DEFAULT | FLAG_NOT_FOUND | CLIENT_NOT_READY | OVERRIDE
```

## Testing (no network, no key)

```php
use Shipeasy\Engine;

$c = Engine::forTesting();
$c->overrideFlag('new_checkout', true);
$c->overrideConfig('billing_copy', ['headline' => 'Hi']);
$c->overrideExperiment('checkout_button', 'treatment', ['color' => 'green']);
$c->getFlag('new_checkout', ['user_id' => 'u1']);   // true
$c->clearOverrides();
```

Offline snapshot: `Engine::fromFile($path)` / `Engine::fromSnapshot($flags, $exps)`.

## Error reporting — see()

```php
use function Shipeasy\see;

try {
    chargeCard($order);
} catch (\Throwable $e) {
    see($e)->causesThe('checkout')->extras(['order_id' => $id])->to('failed to charge');
}
```

`controlFlowException($e)->because('expected')` marks an exception as expected
(reports nothing). `seeViolation('name')->...->to(...)` reports a non-exception.

## OpenFeature

```php
use OpenFeature\OpenFeatureAPI;
use Shipeasy\Engine;
use Shipeasy\OpenFeature\ShipeasyProvider;

$engine = new Engine(getenv('SHIPEASY_SERVER_KEY'));
$engine->initOnce();
OpenFeatureAPI::getInstance()->setProvider(new ShipeasyProvider($engine));
```

Install `open-feature/sdk ^2.0` (optional dep). Booleans → gates; other types →
configs.

## i18n

Server-side: emit the loader tag with the **public client key**; the **browser**
client SDK renders `t()`.

```php
$head = $engine->bootstrapScriptTag($user, ['anonId' => $anonId])
      . $engine->i18nScriptTag($clientKey, 'en:prod');
```

## Anonymous bucketing

```php
use Shipeasy\Identity;
Identity::ensure();   // read/mint __se_anon_id cookie before output; logged-out buckets stably
```
