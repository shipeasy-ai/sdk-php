# Advanced

## Manual exposure

The PHP server is stateless and **never auto-logs** experiment exposures. To get
exposure parity with the browser, call `logExposure()` at the point you actually
present the treatment:

```php
$engine->logExposure('u_123', 'checkout_button');
// or with an attribute map:
$engine->logExposure(['user_id' => 'u_123', 'plan' => 'pro'], 'checkout_button');
```

It re-evaluates the experiment for the user; if enrolled, it POSTs a single
`exposure` event to `/collect`. No-op in local/test mode or when the user isn't
enrolled.

## Private attributes

Attribute names listed in `privateAttributes` are **stripped from outbound event
payloads** (`track()` / exposures). The server evaluates locally, so targeting
still works — only the transmitted properties are scrubbed.

```php
$engine = configure(getenv('SHIPEASY_SERVER_KEY'), null, [
    'privateAttributes' => ['email', 'phone'],
]);
```

`$engine->stripPrivate($props)` applies the same filter to an arbitrary property
array.

## bucketBy (custom bucketing unit)

The bucketing unit is **server-configured per experiment** — set `bucketBy` on
the experiment in the dashboard and the SDK reads it from the blob, bucketing on
that attribute (e.g. `company_id`) instead of the user id. Make sure that
attribute is present in the user map you pass:

```php
$engine->getExperiment('pricing_test', ['user_id' => 'u1', 'company_id' => 'co_42'], $default);
```

## Sticky bucketing

Pass a `Shipeasy\StickyBucketStore` to keep a user in the same variant across
re-randomizations. The interface is a durable get/set over a cookie / Redis /
database:

```php
interface StickyBucketStore {
    public function get(string $unit): ?array;
    public function set(string $unit, string $exp, array $entry): void;
}
```

Wire it through `configure()` (or the `Engine` constructor / factories):

```php
$engine = configure(getenv('SHIPEASY_SERVER_KEY'), null, [
    'stickyStore' => new MyRedisStickyStore(),
]);
```

`Shipeasy\InMemoryStickyStore` is a built-in non-durable implementation useful in
a single long-running process or tests.

## Anonymous-visitor bucketing

For logged-out traffic you need a *stable* unit so a fractional rollout buckets
the same on the server and in the browser. Call `Identity::ensure()` once early
in your bootstrap (before any output) — it reads or mints the shared
`__se_anon_id` first-party cookie used by every Shipeasy SDK:

```php
use Shipeasy\Identity;

Identity::ensure();                 // read or mint __se_anon_id (+ Set-Cookie)
$client = new Client([]);
$client->getFlag('new_checkout');   // buckets on the cookie automatically
```

An explicit `user_id` / `anonymous_id` always wins. Works in plain PHP,
WordPress, Laravel, Symfony, Slim — anywhere `$_COOKIE` / `setcookie()` exist
(call it from middleware / a service provider in a framework). The cookie is
non-`HttpOnly` by design so the browser SDK buckets identically; a request with
**no** unit still resolves a fully-rolled (100%) gate as on.

## Offline snapshots

Build an engine from a baked blob instead of the network (air-gapped/edge hosts,
reproducible CI). The **real** eval runs against the snapshot (overrides apply on
top); `init()`/`initOnce()`/`track()` are no-ops and telemetry is off.

```php
use Shipeasy\Engine;

// From a JSON file: { "flags": <body of /sdk/flags>, "experiments": <body of /sdk/experiments> }
$c = Engine::fromFile('/etc/shipeasy/snapshot.json');

// Or from already-decoded blobs:
$c = Engine::fromSnapshot($flagsBody, $experimentsBody);

$c->getFlag('new_checkout', ['user_id' => 'u1']);   // evaluated, no network
```

## Change listeners

`onChange()` registers a callback fired whenever the engine refreshes with a
**new** server response (a 200, never a 304), returning an unsubscribe callable:

```php
$unsub = $engine->onChange(function () {
    // re-read flags here; the blob just changed
});
$unsub();   // stop listening
```

> **PHP runtime caveat.** This SDK runs no background poll thread. Under classic
> **PHP-FPM the engine is rebuilt per request**, so a listener will not fire on
> its own — change listeners are mainly relevant to **long-running runtimes**
> (Swoole, RoadRunner, queue/CLI workers) that keep an engine alive and call
> `refresh()` on a schedule. Each listener is wrapped in `try/catch`, so a
> throwing listener never breaks a refresh; listeners never fire in
> `forTesting()` / snapshot mode.

## SSR bootstrap

See [i18n](i18n.md) for `bootstrapScriptTag()` / `i18nScriptTag()` and the raw
`evaluate($user)` payload (`['flags', 'configs', 'experiments', 'killswitches']`).
