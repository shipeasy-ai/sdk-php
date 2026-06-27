# Configuration

Configure the process-wide engine **once** at startup with `Shipeasy\configure`.
It is **first-config-wins** — the first call builds and registers the engine;
later calls return the existing one.

```php
use function Shipeasy\configure;

$engine = configure(
    getenv('SHIPEASY_SERVER_KEY'),   // the Shipeasy SERVER key (never the client key)
    fn ($u) => [                     // optional attributes transform
        'user_id' => $u->id,
        'plan'    => $u->plan,
    ],
    [                                // optional engine options
        'env'                => 'prod',
        'baseUrl'            => 'https://edge.shipeasy.dev',
        'disableTelemetry'   => false,
        'telemetryUrl'       => null,
        'privateAttributes'  => ['email'],
        'stickyStore'        => null,
    ],
);
```

## Parameters

| Param | Type | Notes |
| --- | --- | --- |
| `$apiKey` | `string` | The Shipeasy **server** key. Required. |
| `$attributes` | `callable\|null` | `(yourUser) => attributeMap`. **Default is identity** — omit it when your user array already IS the Shipeasy attribute map. Runs once per `new Client($user)`. |
| `$opts` | `array` | Extra Engine options (below). |

### `$opts` keys

| Key | Default | Meaning |
| --- | --- | --- |
| `env` | `'prod'` | The read environment for the blob. |
| `baseUrl` | `https://edge.shipeasy.dev` | Edge API base. |
| `disableTelemetry` | `false` | Opt out of the usage-telemetry beacon. |
| `telemetryUrl` | (built-in) | Override the telemetry endpoint. |
| `privateAttributes` | `[]` | Attribute names stripped from outbound event payloads (LD/Statsig `privateAttributes`). See [Advanced](advanced.md). |
| `stickyStore` | `null` | A `Shipeasy\StickyBucketStore` for durable experiment bucketing. See [Advanced](advanced.md). |

## The fetch model (init / poll vs one-shot)

`configure()` fires the **one-shot fetch** immediately, so
`new Client($user)->getFlag(...)` resolves against real rules without an
explicit `init()` call.

PHP has no thread-based polling primitive, so there is **no background poll**:

- **PHP-FPM / classic request lifecycle** — `init()` fetches once per request.
  Nothing else to do.
- **Long-running runtimes (Swoole, RoadRunner, queue/CLI workers)** — call
  `$engine->init()` (or `$engine->refresh()`) from a periodic task to keep the
  blob fresh across requests.

`init()` is an alias for `initOnce()`; `destroy()` is a no-op.

## The Engine return

`configure()` returns the single `Shipeasy\Engine`. Keep it if you need the
heavyweight surface (`track()`, `logExposure()`, `refresh()`, `see()`,
`forTesting()`):

```php
use Shipeasy\Engine;

$engine = new Engine(getenv('SHIPEASY_SERVER_KEY'));
$engine->init();
$enabled = $engine->getFlag('new_checkout', ['user_id' => 'u_123']);
$engine->track('u_123', 'purchase', ['amount' => 49]);
```

Constructing any `Engine` registers it as the **default** (last-wins) used by the
package-level `see()` functions and the user-bound `Client`. `configure()` uses
first-config-wins.

## Environment variables

The SDK does not read env vars itself — pass `getenv('SHIPEASY_SERVER_KEY')`
explicitly. Conventionally:

- `SHIPEASY_SERVER_KEY` — the server key for `configure()` / `new Engine(...)`.
- The **public client key** (a separate value) is only used for the i18n loader
  `<script>` tag, never for server evaluation. See [i18n](i18n.md).
