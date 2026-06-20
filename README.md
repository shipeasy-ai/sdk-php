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

## Server-side rendering (SSR)

Emit the request's evaluated flags as a declarative `<script>` tag so the
browser SDK has them on first paint. `bootstrapScriptTag()` carries the payload
in `data-*` attributes (**no key**); the static `se-bootstrap.js` loader
hydrates `window.__SE_BOOTSTRAP` and writes the `__se_anon_id` cookie so the
browser buckets identically to the server.

```php
$user = ['user_id' => 'u_123'];

// Two tags for the document <head>. The PUBLIC client key (not the server
// key) goes on the i18n loader tag.
$head = $c->bootstrapScriptTag($user, ['anonId' => $anonId])
      . $c->i18nScriptTag($clientKey, 'en:prod');

// …or get the raw payload (['flags', 'configs', 'experiments', 'killswitches']):
$boot = $c->evaluate($user);
```

The `$opts` array also accepts `'i18nProfile'` and `'baseUrl'`
(defaults to `https://cdn.shipeasy.ai`).

## Default values

`getFlag()` and `getConfig()` take an optional default that is returned **only
when the entity cannot be evaluated** — not when it evaluates to a "falsy"
result:

```php
// getFlag's default is returned ONLY when the gate is not initialized
// (CLIENT_NOT_READY) or not present in the blob (FLAG_NOT_FOUND). A gate that
// is off or whose rules/rollout don't match returns false, NOT the default.
$c->getFlag('new_checkout', ['user_id' => 'u1'], true);   // default = true

// getConfig's default is returned when the config key is absent.
$c->getConfig('billing_copy', ['headline' => 'Welcome']);
```

The defaults are additive — `getFlag($name, $user)` still defaults to `false`
and `getConfig($name)` still defaults to `null`.

## Evaluation detail

`getFlagDetail()` returns a `FlagDetail` (`->value`, `->reason`) explaining how a
flag resolved. The reason is one of the `FlagDetail` constants:

| Reason | Meaning |
| --- | --- |
| `FlagDetail::OVERRIDE` | A local `overrideFlag()` supplied the value. |
| `FlagDetail::CLIENT_NOT_READY` | The client never initialized (no blob fetched). |
| `FlagDetail::FLAG_NOT_FOUND` | The gate is not in the fetched blob. |
| `FlagDetail::OFF` | The gate exists but is disabled. |
| `FlagDetail::RULE_MATCH` | The gate evaluated **true** (a rule/rollout matched). |
| `FlagDetail::DEFAULT` | The gate evaluated **false** (nothing matched). |

```php
$d = $c->getFlagDetail('new_checkout', ['user_id' => 'u1']);
$d->value;   // bool
$d->reason;  // e.g. FlagDetail::RULE_MATCH
```

`getFlag()` is implemented on top of `getFlagDetail()`. The usage-telemetry
beacon fires exactly once per `getFlagDetail()` call — and never for an override.

## Change listeners

`onChange()` registers a callback fired whenever the client refreshes its data
with a **new** server response, and returns an unsubscribe callable:

```php
$unsub = $c->onChange(function () {
    // re-read flags here; the blob just changed
});
// ...
$unsub();   // stop listening
```

> **PHP runtime caveat.** PHP is request-scoped and this SDK runs **no
> background poll thread**, so listeners fire only when data is *actually*
> refreshed (a 200 from a subsequent `init()`/`refresh()`, never a 304) and
> never in `forTesting()`/snapshot mode. Under classic **PHP-FPM the client is
> rebuilt per request**, so a listener will not fire on its own — change
> listeners are mainly relevant to **long-running runtimes** (Swoole,
> RoadRunner, queue/CLI workers) that keep a client alive across requests and
> call `refresh()` on a schedule. Each listener is wrapped in `try/catch`, so a
> throwing listener never breaks a refresh.

## Offline snapshot

For air-gapped/edge hosts or reproducible CI you can build a client from a baked
blob instead of the network. Evaluations run the **real** eval against the
snapshot (overrides apply on top); `init()`/`initOnce()`/`track()` are no-ops and
telemetry is off.

```php
// From a JSON file: { "flags": <body of /sdk/flags>, "experiments": <body of /sdk/experiments> }
$c = Client::fromFile('/etc/shipeasy/snapshot.json');

// Or from already-decoded blobs:
$c = Client::fromSnapshot($flagsBody, $experimentsBody);

$c->getFlag('new_checkout', ['user_id' => 'u1']);   // evaluated, no network
```

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

## Testing

In unit tests you want deterministic flag/config/experiment values with **no
network and no API key**. `Client::forTesting()` builds a client that never
fetches (`init()`/`initOnce()` are no-ops), never sends telemetry, and whose
`track()` is a no-op. Seed each entity with the override setters; an override
always wins over the fetched blob.

```php
use Shipeasy\Client;

$c = Client::forTesting();   // no key, no network

// Flags
$c->overrideFlag('new_checkout', true);
$c->getFlag('new_checkout', ['user_id' => 'u1']);        // true

// Configs
$c->overrideConfig('billing_copy', ['headline' => 'Hi']);
$c->getConfig('billing_copy');                            // ['headline' => 'Hi']

// Experiments — returns an inExperiment result with your group + params
$c->overrideExperiment('checkout_button', 'treatment', ['color' => 'green']);
$r = $c->getExperiment('checkout_button', ['user_id' => 'u1'], ['color' => 'blue']);
$r->inExperiment;  // true
$r->group;         // 'treatment'
$r->params;        // ['color' => 'green']  (override params beat defaultParams)

// track() is a no-op in test mode — safe to call, sends nothing
$c->track('u1', 'purchase', ['amount' => 49]);

// Reset between cases
$c->clearOverrides();
```

The override setters (`overrideFlag`, `overrideConfig`, `overrideExperiment`,
`clearOverrides`) also work on a normal `new Client(...)` instance — an
overridden key short-circuits before any network read.

## OpenFeature

If your app is standardized on the CNCF [OpenFeature](https://openfeature.dev)
API, plug Shipeasy in as the backing provider. `open-feature/sdk` (`^2.0`) is an
optional dependency — install it in your app (`composer require open-feature/sdk`).

```php
use OpenFeature\OpenFeatureAPI;
use Shipeasy\Client;
use Shipeasy\OpenFeature\ShipeasyProvider;

$client = new Client($_ENV['SHIPEASY_SERVER_KEY']);
$client->initOnce();

$api = OpenFeatureAPI::getInstance();
$api->setProvider(new ShipeasyProvider($client));

$of = $api->getClient();
$on = $of->getBooleanValue('new_checkout', false, $ctx); // bool
```

`ShipeasyProvider` is a pure adapter over `Client` — evaluation is unchanged.
Booleans evaluate gates; strings/integers/floats/objects route to dynamic
configs (`getConfig`). The evaluation context's targeting key becomes the
`user_id` and its attributes are carried through for targeting. Reasons map onto
OpenFeature's `Reason`/`ErrorCode` (`RULE_MATCH→TARGETING_MATCH`,
`OFF→DISABLED`, `OVERRIDE→STATIC`, missing flag → `FLAG_NOT_FOUND`,
uninitialized client → `PROVIDER_NOT_READY`, wrong config type → `TYPE_MISMATCH`).
