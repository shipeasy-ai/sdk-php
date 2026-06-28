# Advanced

## Manual exposure

The PHP server is stateless and **never auto-logs** experiment exposures. To get
exposure parity with the browser, call `logExposure()` on the bound `Client` at
the point you actually present the treatment:

```php
use Shipeasy\Client;

$client = new Client($currentUser);   // construct once per callsite
$r      = $client->getExperiment('checkout_button', ['color' => 'blue']);

$client->logExposure('checkout_button');   // emits one exposure if enrolled
```

It re-evaluates the experiment for the bound user; if enrolled, it POSTs a single
`exposure` event to `/collect`. No-op in test/offline mode or when the user isn't
enrolled.

## Private attributes

Attribute names listed in `privateAttributes` are **stripped from outbound event
payloads** (`track()` / exposures). The server evaluates locally, so targeting
still works — only the transmitted properties are scrubbed.

```php
use function Shipeasy\configure;

configure($_ENV['SHIPEASY_SERVER_KEY'], null, [
    'privateAttributes' => ['email', 'phone'],
]);
```

## bucketBy (custom bucketing unit)

The bucketing unit is **server-configured per experiment** — set `bucketBy` on
the experiment in the dashboard and the SDK reads it from the blob, bucketing on
that attribute (e.g. `company_id`) instead of the user id. Make sure that
attribute is present on the user you bind, via the `attributes` transform or the
user object itself:

```php
use function Shipeasy\configure;
use Shipeasy\Client;

configure($_ENV['SHIPEASY_SERVER_KEY'], fn ($u) => [
    'user_id'    => $u->id,
    'company_id' => $u->companyId,   // present so bucketBy: company_id works
]);

$client = new Client($currentUser);   // construct once per callsite
$r      = $client->getExperiment('pricing_test', $default);
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

Wire it through `configure()`:

```php
use function Shipeasy\configure;

configure($_ENV['SHIPEASY_SERVER_KEY'], null, [
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
use Shipeasy\{Client, Identity};

Identity::ensure();                 // read or mint __se_anon_id (+ Set-Cookie)
$client = new Client([]);           // construct once per callsite
$client->getFlag('new_checkout');   // buckets on the cookie automatically
```

An explicit `user_id` / `anonymous_id` always wins. Works in plain PHP,
WordPress, Laravel, Symfony, Slim — anywhere `$_COOKIE` / `setcookie()` exist
(call it from middleware / a service provider in a framework). The cookie is
non-`HttpOnly` by design so the browser SDK buckets identically; a request with
**no** unit still resolves a fully-rolled (100%) gate as on.

## Offline snapshots

Evaluate the **real** rules against a baked blob instead of the network
(air-gapped / edge hosts, reproducible CI) with `Shipeasy\configureForOffline()`.
See [Testing](testing.md) for the full snapshot JSON shape.

```php
use function Shipeasy\configureForOffline;
use Shipeasy\Client;

// From a JSON file: { "flags": <body of /sdk/flags>, "experiments": <body of /sdk/experiments> }
configureForOffline(['path' => '/etc/shipeasy/snapshot.json']);

// Or from already-decoded blobs:
configureForOffline(['snapshot' => ['flags' => $flagsBody, 'experiments' => $expBody]]);

(new Client(['user_id' => 'u1']))->getFlag('new_checkout');   // evaluated, no network
```

## Change listeners

`Shipeasy\onChange()` registers a callback fired whenever the SDK refreshes with a
**new** server response (a 200, never a 304), returning an unsubscribe callable:

```php
use function Shipeasy\onChange;

$unsub = onChange(function () {
    // re-read flags here; the blob just changed
});
$unsub();   // stop listening
```

> **PHP runtime caveat.** This SDK runs no background poll thread. Under classic
> **PHP-FPM the SDK is rebuilt per request**, so a listener will not fire on its
> own — change listeners are mainly relevant to **long-running runtimes**
> (Swoole, RoadRunner, queue/CLI workers) that keep the SDK alive and refresh the
> blob on a schedule. Each listener is wrapped in `try/catch`, so a throwing
> listener never breaks a refresh; listeners never fire in test/snapshot mode.

## SSR bootstrap

See [i18n](i18n.md) for `Shipeasy\bootstrapScriptTag()` / `Shipeasy\i18nScriptTag()`
and wiring the browser SDK from the server-rendered `<head>`.
