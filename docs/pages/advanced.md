# Advanced

## Exposure logging

`universe($name)->assign()` **auto-logs** a single exposure when the unit is
enrolled — reading *is* the exposure, so there is no manual `logExposure`
primitive. The exposure POSTs one `exposure` event to `/collect`, deduped per
process (a bounded set keyed by `unit:experiment:group`), so repeated `assign()`
calls for the same unit in one request/worker emit one exposure:

```php
use Shipeasy\Client;

$client = new Client($currentUser);   // construct once per callsite
$cta    = $client->universe('checkout')->assign();   // enrolled → one exposure logged
// …repeat assign() calls in this process do not re-log.
```

No-op in test/offline mode or when the user isn't enrolled.

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
$exp    = $client->universe('pricing')->assign();   // buckets on company_id
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

## DevTools overrides on server reads

When a signed-in teammate uses the DevTools overlay to force a gate, config or
experiment group (including `?se_gate_checkout=true` in the URL), the overlay
mirrors it into a signed `se_ov` cookie that rides every same-origin request.
Server reads through `new Client($user)` honour it for that request only:

- **PHP-FPM** — nothing to do. After `configure()`, the first read picks up
  `$_COOKIE['se_ov']`.
- **Laravel / Symfony / PSR-15 / Swoole / RoadRunner** — wrap the request in
  `Shipeasy\withRequestOverrides()`, passing the raw `Cookie` header:

```php
// Laravel middleware
public function handle($request, \Closure $next)
{
    return Shipeasy\withRequestOverrides($request->headers->get('Cookie'), fn () => $next($request));
}
```

Precedence: programmatic `overrideFlag()` etc. > signed cookie > normal
evaluation. `getFlag` / `getFlagDetail` return the forced value with reason
`FlagDetail::OVERRIDE`; `getKillswitch($name)` honours the same gate override
(a per-key `$switchKey` read is left alone); `getConfig` returns the forced
value; `universe()->assign()` forces the experiment group.

It is **fail closed**: the cookie is verified once per request against the
project override key Shipeasy serves with your flags (an HMAC-SHA256 signature,
checked with `hash_equals`). No key served, a bad signature, a wrong key id or an
expired cookie means no overrides. A bare unsigned `?se_gate_` URL parameter
reaches the browser SDK only — by design it never changes server-side reads.
Outside a request scope with no cookie, behaviour is unchanged.

### Dev-only `se_ov_dev` (no DevTools sign-in)

On a local/dev/test stack you can let `?se_gate_x=true` reach server reads
without signing in to DevTools. The browser SDK mirrors the URL overrides into an
unsigned `se_ov_dev` cookie (non-production builds only), and the server honours
it only when **both** guards hold:

1. you opted in: `configure($key, null, ['devOverrides' => true])`; and
2. the process is non-production (`SHIPEASY_ENV`, then `APP_ENV`; unset means
   production). `configureForTesting()` counts as non-production.

With the option on in a production process it is ignored and logged once, and the
cookie is never even read: it is **never active in production**. It uses the same
`withRequestOverrides()` scope (or `$_COOKIE` under PHP-FPM). Precedence:
programmatic `overrideFlag()` etc. > signed `se_ov` > `se_ov_dev` > normal
evaluation (the signed cookie wins per name on conflicts); reads report
`FlagDetail::OVERRIDE`.

### Frontend and API on different subdomains

When the frontend is `app.example.com` and the API is `api.example.com`, the
browser SDK scopes the override cookies to the registrable domain automatically
(or to `overrideCookieDomain` when you set it), so the API sees them on its own
`Cookie` header and nothing changes server-side. For token-auth or cross-origin
APIs that do not receive cookies, send the `X-Shipeasy-Overrides` header from the
browser (`headers: { ...getOverrideHeaders() }` from `@shipeasy/sdk/client`) and
allow it in the API's CORS `Access-Control-Allow-Headers`. Its value is cookie
syntax (`se_ov=...; se_ov_dev=...`). Under PHP-FPM
`$_SERVER['HTTP_X_SHIPEASY_OVERRIDES']` is read automatically; elsewhere pass it
as the third argument:

```php
Shipeasy\withRequestOverrides(
    $request->headers->get('Cookie'),
    fn () => $next($request),
    $request->headers->get('X-Shipeasy-Overrides'),
);
```

For each cookie name the `Cookie` header wins and the header only fills in when
the cookie is absent. The same verification applies: the signed cookie must
verify, and the dev cookie is not even parsed unless `devOverrides` is on in a
non-production process.

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
