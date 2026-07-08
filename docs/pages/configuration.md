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
        'disableTelemetry'   => false,
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
| `disableTelemetry` | `false` | Opt out of the usage-telemetry beacon. |
| `telemetryUrl` | (built-in) | Override the telemetry endpoint. |
| `privateAttributes` | `[]` | Attribute names stripped from outbound event payloads (LD/Statsig `privateAttributes`). See [Advanced](advanced.md). |
| `stickyStore` | `null` | A `Shipeasy\StickyBucketStore` for durable experiment bucketing. See [Advanced](advanced.md). |
| `logLevel` | `'warn'` | The SDK's own diagnostic verbosity: `'silent'`, `'error'`, `'warn'`, `'info'`, `'debug'`. See below. |
| `disableInternalErrorReporting` | `false` | Opt out of internal SDK-error self-reporting. See below. |

## Fail-safe reads & the `logLevel` option

Every **runtime read** — `getFlag`, `getFlagDetail`, `getConfig`,
`getExperiment`, `getKillswitch`, plus `track`, `logExposure`, and the `see()`
chain — is **fail-safe**: it never throws into your request. If a blob is
malformed or a hook you supplied (e.g. a `stickyStore` or attributes transform)
throws mid-read, the call swallows the error and returns its documented default
(`getFlag` → the `$default` bool, `getConfig` → `$default`, `getExperiment` → a
not-enrolled result with `group="control"` and your `$defaultParams`,
`getKillswitch` → `false`, `track`/`logExposure` → a no-op). **Setup and
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

The SDK does not read env vars itself — pass `$_ENV['SHIPEASY_SERVER_KEY']`
explicitly. Conventionally:

- `SHIPEASY_SERVER_KEY` — the server key for `configure()`.
- The **public client key** (a separate value) is only used for the i18n loader
  `<script>` tag, never for server evaluation. See [i18n](i18n.md).
