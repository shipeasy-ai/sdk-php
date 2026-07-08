# Configuration

`Shipeasy\configure()` is the single setup call. The full reference — install,
per-framework wiring, and the `$opts` table — lives on
[Installation](installation.md). This page focuses on the options themselves.

Configure the SDK **once** at startup. It is **first-config-wins** — the first
call sets up the SDK and fires the one-shot fetch; later calls are ignored.

```php
use function Shipeasy\configure;

configure(
    $_ENV['SHIPEASY_SERVER_KEY'],   // the Shipeasy SERVER key (never the client key)
    fn ($u) => [                    // optional attributes transform (default: identity)
        'user_id' => $u->id,
        'plan'    => $u->plan,
    ],
    [                               // optional configure() options
        'env'                => 'prod',
        'baseUrl'            => 'https://api.shipeasy.ai',
        'isNetworkEnabled'   => null,   // null = env-derived (on in prod, off elsewhere)
        'disableTelemetry'   => null,   // null = env-derived (off outside prod)
        'telemetryUrl'       => null,
        'privateAttributes'  => ['email'],
        'stickyStore'        => null,
        'logLevel'           => 'warn',
        'disableInternalErrorReporting' => false,
    ],
);
```

## Arguments

| Arg | Type | Notes |
| --- | --- | --- |
| `$apiKey` | `string` | The Shipeasy **server** key. Required. |
| `$attributes` | `callable\|null` | `(yourUser) => attributeMap`. **Default is identity** — omit it when your user array already IS the Shipeasy attribute map. Runs once per `new Client($user)`. |
| `$opts` | `array` | The options below (all optional). |

## `$opts` keys

| Key | Default | Meaning |
| --- | --- | --- |
| `env` | `'prod'` | The read environment for the blob. |
| `baseUrl` | `https://api.shipeasy.ai` | Edge API base. |
| `isNetworkEnabled` | env-derived | Master switch for **all** outbound requests. `null` ⇒ on in production, off everywhere else. See [Environment-derived egress defaults](#environment-derived-egress-defaults). |
| `disableTelemetry` | env-derived | Opt out of the usage-telemetry beacon. `null` ⇒ on in production, off everywhere else (and always off when `isNetworkEnabled` is false). |
| `telemetryUrl` | (built-in) | Override the telemetry endpoint. |
| `privateAttributes` | `[]` | Attribute names stripped from outbound event payloads (LD/Statsig `privateAttributes`). See [Advanced](advanced.md). |
| `stickyStore` | `null` | A `Shipeasy\StickyBucketStore` for durable experiment bucketing. See [Advanced](advanced.md). |
| `logLevel` | `'warn'` | The SDK's own diagnostic verbosity: `'silent'`, `'error'`, `'warn'`, `'info'`, `'debug'`. See below. |
| `disableInternalErrorReporting` | `false` | Opt out of internal SDK-error self-reporting. See below. |

## Environment-derived egress defaults

By default the SDK is **quiet outside production**: on a dev machine or in CI it
makes **no** outbound request — no rule-blob fetch, no `track`, no exposure, no
`see()` report, no usage telemetry — until you opt in. In production it behaves
exactly as before (fully on). This keeps an app that embeds the SDK from phoning
home from a laptop or a test run.

Two options control egress, and both **default to on in production and off in
every other environment**:

- **`isNetworkEnabled`** — the master switch for **all** outbound requests. When
  off, the SDK is fully offline: reads return your in-code defaults / overrides
  and nothing is sent.
- **`disableTelemetry`** — the usage-telemetry beacon specifically. Always off
  when `isNetworkEnabled` is off.

An explicitly-passed value **always wins** over the environment default. Pass
`'isNetworkEnabled' => true` to force egress on outside production, or
`'isNetworkEnabled' => false` to force a production deploy fully offline.
`configureForTesting()` / `configureForOffline()` are always offline regardless.

### How "production" is decided

The decision follows this precedence:

1. A native runtime env var, checked in order: **`SHIPEASY_ENV`**, then
   **`APP_ENV`** (the Laravel/Symfony convention), then **`ENV`**. A value of
   `production` or `prod` (case-insensitive) ⇒ production; **any other present
   value** (`development`, `staging`, `test`, …) ⇒ not production.
2. If none of those is set, fall back to the SDK's own **`env`** option (which
   defaults to `'prod'`) — so a real production deploy with no env var stays on.

Under Laravel this is automatic: the framework sets `APP_ENV`, so a `local` /
`testing` app is quiet by default and a `production` app is on — no extra config.
The Laravel `network_enabled` config key (env `SHIPEASY_NETWORK_ENABLED`) maps to
`isNetworkEnabled` when you want to force it.

> **Behaviour change (0.16.0):** before 0.16.0 the SDK always attempted egress.
> To restore the old always-on behaviour, set `SHIPEASY_ENV=production` (or
> `APP_ENV=production`) in the environment, or pass
> `'isNetworkEnabled' => true` to `configure()`.

## Fail-safe reads & the `logLevel` option

Every **runtime read** — `getFlag`, `getFlagDetail`, `getConfig`,
`universe()->assign()`, `getKillswitch`, plus `track` and the `see()`
chain — is **fail-safe**: it never throws into your request. If a blob is
malformed or a hook you supplied (e.g. a `stickyStore` or attributes transform)
throws mid-read, the call swallows the error and returns its documented default
(`getFlag` → the `$default` bool, `getConfig` → `$default`,
`universe()->assign()` → a not-enrolled `Assignment` (`group === null`, `get()`
falls back to the universe default or your fallback), `getKillswitch` → `false`,
`track` → a no-op). **Setup and
lifecycle calls stay loud** — constructing `new Client()` before `configure()`,
`configureForOffline` misconfig, and `init()`/`refresh()` still throw so boot-time
mistakes surface.

When a read falls back, the SDK logs why via `error_log('[shipeasy] …')`, gated
by `logLevel`:

| Level | Emits |
| --- | --- |
| `silent` | nothing |
| `error` | unexpected errors only |
| `warn` (default) | errors + recoverable warnings |
| `info` | + informational |
| `debug` | everything |

Ordering is `silent < error < warn < info < debug`; a message at level L is
emitted iff the configured level is ≥ L. An unknown value falls back to `warn`.
Set `'silent'` to mute the SDK entirely.

## Internal error self-reporting (`disableInternalErrorReporting`)

When a fail-safe read swallows one of the **SDK's own** internal errors (a bug on
Shipeasy's side, not yours), the SDK also ships a small structured error event to
**Shipeasy's own project** so the SDK team can track SDK-internal failures across
every app it runs in. This is separate from your `see()` reports: it never
authenticates with your key and never lands in **your** Errors tab. It's
fire-and-forget, never blocks, never throws, and is rate-limited/deduped just like
`see()`.

It's ON by default and off automatically in `configureForTesting` /
`configureForOffline`. Set `disableInternalErrorReporting => true` (or the Laravel
`SHIPEASY_DISABLE_INTERNAL_ERROR_REPORTING` env var) to opt out entirely.

## The fetch model (no background poll)

`configure()` fetches the rule blob **once per request** the moment it runs, so
`new Client($user)->getFlag(...)` resolves against real rules immediately — there
is no separate init step and no `poll` option.

PHP is request-scoped and has **no background poll thread**:

- **PHP-FPM / classic request lifecycle** — `configure()` fetches once per
  request. Nothing else to do.
- **Long-running runtimes (Swoole, RoadRunner, queue/CLI workers)** — a process
  serves many requests, so refresh the blob on a schedule to keep it fresh across
  requests.

## Environment variables

The SDK does not read env vars for its **keys** — pass
`$_ENV['SHIPEASY_SERVER_KEY']` explicitly. Conventionally:

- `SHIPEASY_SERVER_KEY` — the server key for `configure()`.
- The **public client key** (a separate value) is only used for the i18n loader
  `<script>` tag, never for server evaluation. See [i18n](i18n.md).

The SDK **does** read the environment to decide the egress default (see
[Environment-derived egress defaults](#environment-derived-egress-defaults)):

- `SHIPEASY_ENV`, then `APP_ENV`, then `ENV` — `production`/`prod` ⇒ egress on;
  anything else ⇒ egress off (unless you pass `isNetworkEnabled` explicitly).
- `SHIPEASY_NETWORK_ENABLED` (Laravel `network_enabled` config) — force the
  master switch on/off regardless of environment.
