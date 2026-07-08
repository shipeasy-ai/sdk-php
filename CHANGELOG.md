# Changelog

## 0.13.0 — 2026-07-07

- **Fail-safe runtime reads.** Every runtime read/track/report method now
  guarantees it will **never throw into the caller** and instead returns its
  documented safe default: `getFlag`/`getFlagDetail`→the `$default` bool (or a
  `CLIENT_NOT_READY` detail), `getConfig`→`$default`, `getExperiment`→a
  not-enrolled `ExperimentResult` (`inExperiment=false`, `group="control"`,
  `params=$defaultParams`), `getKillswitch`→`false`, `track`/`logExposure`→a
  no-op, and the `see()`/`seeViolation()` chains stay inert. This covers both the
  `Engine` methods and the bound `Client::*` equivalents, so a malformed blob or a
  user-supplied `StickyBucketStore`/attributes hook that throws mid-read can no
  longer surface. Setup/lifecycle calls (constructing `new Client()` before
  `configure()`, offline/`fromFile` misconfig, `init()`/`refresh()` network) stay
  loud — the guarantee is runtime reads only.
- **New `logLevel` option + leveled logger.** `configure()` (and
  `Engine::__construct`) accept `logLevel` — one of `'silent'`, `'error'`,
  `'warn'`, `'info'`, `'debug'` (ordering `silent<error<warn<info<debug`; a
  message at level L is emitted iff the configured level is ≥ L; unknown → `warn`,
  which is also the default). It drives a tiny `Shipeasy\Logger` that emits the
  SDK's own diagnostics via `error_log('[shipeasy] …')` and never throws. The
  fail-safe reads log at this level when something unexpected happens; set
  `'silent'` to mute the SDK entirely. The Laravel config gains
  `log_level` (`SHIPEASY_LOG_LEVEL`, default `warn`), wired through the service
  provider.

## 0.12.1

- **Admin API client regenerated from the canonical OpenAPI spec (2.0.0).** The
  0.12.0 client was generated from a stale 1.0.0 subset; this regenerates it from
  the full spec, adding the connectors, errors, keys, drafts, profiles and
  api-keys endpoints. `AdminClient` resource accessors are now `flags()`,
  `configs()`, `killswitch()`, `experiments()`, `universes()`, `attributes()`,
  `metrics()`, `events()`, `ops()`, `alerts()`, `projects()`, `profiles()`,
  `keys()`, `drafts()`, `errors()`, `connectors()`, `apiKeys()` (renamed/added to
  track the spec's tags).

## 0.12.0

- **Optional Admin API client** — a new opt-in `Shipeasy\Admin\AdminClient` for
  *administering* resources (create gates, start experiments, manage configs/
  killswitches/universes/metrics/events, …) from server code. It is a raw client
  **generated from the Shipeasy OpenAPI spec** (1:1 with the REST API — id-based,
  basis-points, snake_case; no name→id or percent→bp ergonomics, which stay in
  the CLI/MCP).
  - Off by default: the generated client needs `guzzlehttp/guzzle`, declared in
    composer `suggest` (NOT `require`), so the base SDK never forces it. Opt in
    with `composer require guzzlehttp/guzzle`.
  - `new AdminClient($apiKey, $projectId)` wires bearer auth + `X-Project-Id`
    scoping (base URL defaults to `https://shipeasy.ai`); resource groups are
    reached as `$admin->gates()`, `$admin->experiments()`, … (gates, configs,
    killswitches, experiments, universes, metrics, events, alertRules, attributes,
    projects, ops, i18n).
  - Regenerate after a contract change: refresh `admin/openapi.json` then run
    `bash scripts/gen_admin.sh` (only `src/Shipeasy/Admin/Generated/` is rewritten;
    the `AdminClient` shim is preserved). Generator pinned via `openapitools.json`.

## 0.11.0

Laravel integration — the Laravel-native equivalent of the Ruby SDK's Rails
generator. Auto-discovered, config-driven, zero hand-wiring.

### Added

- **`Shipeasy\Laravel\ShipeasyServiceProvider`** — auto-discovered via
  `extra.laravel.providers`. Merges + publishes `config/shipeasy.php`, registers
  the `shipeasy:install` command, auto-calls `Shipeasy\configure()` on boot when
  `config('shipeasy.server_key')` is set (resolving the optional invokable
  `attributes` transform from the container), and registers the
  `@shipeasyBootstrap($user)` / `@shipeasyI18n` Blade directives for the layout
  `<head>`.
- **`php artisan shipeasy:install {--i18n} {--force}`** — publishes the config and
  seeds `SHIPEASY_SERVER_KEY=` (and, with `--i18n`, `SHIPEASY_CLIENT_KEY=`) into
  `.env` / `.env.example`, then prints where to place the Blade directives. Honors
  the Laravel convention of shipping directives the user places (no layout
  codegen).
- **`Shipeasy\Laravel\Installer`** — framework-free, unit-tested core
  (`ensureEnvKeys()` idempotent dotenv append, `nextSteps()`).
- **`config/shipeasy.php`** — `server_key`, `client_key`, `env`, `attributes`,
  `i18n_profile`.

These Laravel classes depend on Illuminate base classes provided by the host app
at runtime; PSR-4 autoload is lazy, so they never load in a non-Laravel context
and add **no** runtime dependency (`composer.json` `require` unchanged).

## 0.10.0

The uniform SDK DX standard (experiment-platform doc 23). The documented surface
is now exactly `Shipeasy\configure()` (+ the test/offline siblings) and the bound
`new Shipeasy\Client($user)`; the `Engine` stays public but undocumented.

### Added

- **`Shipeasy\configureForTesting([...])`** — no api key, zero network; seeds
  `flags`/`configs`/`experiments` overrides and registers the global engine so the
  bound `new Client($user)` reads them. **Replaces** prior config (unlike
  `configure`'s first-config-wins) so a test suite can reconfigure between cases.
- **`Shipeasy\configureForOffline([...])`** — evaluates the **real** rules from an
  in-memory `snapshot` or a JSON `path`, with overrides layered on top; also
  replaces prior config.
- **Package-level functions** so the docs never name the `Engine`:
  `Shipeasy\overrideFlag` / `overrideConfig` / `overrideExperiment` /
  `clearOverrides`, `onChange`, `bootstrapScriptTag`, `i18nScriptTag` — delegating
  to the configured global engine.
- **`ShipeasyProvider` global form** — `new ShipeasyProvider()` (no argument)
  resolves the engine built by `configure()`; passing an explicit `Engine` stays
  supported.
- **`bin/shipeasy-skill`** — the opt-in installer (`vendor/bin/shipeasy-skill
  install` / `print`) that copies the bundled agent skill into a consumer's
  project.

### Changed

- `README.md` is now **generated** from `docs/` by `scripts/gen-readme.php`
  (CI enforces it); the docs were rewritten Engine-free around `configure()` +
  `Client`, with new `metrics/track` + `ops/see` snippet groups and specific
  placeholders.

## 0.9.0

- **Add `track()`/`logExposure()` to the bound `Client`.** Experiments are now
  end-to-end Client-only — the user-bound `Shipeasy\Client` (from `configure()`
  + `new Client($user)`) gains `track(string $event, array $props = [])` and
  `logExposure(string $experiment)`, with **no user argument**. `track()` derives
  the unit from the bound attribute map (`user_id`, else `anonymous_id`) and
  `logExposure()` forwards the bound map. Both delegate to the engine; the
  low-level `Engine::track($userId, $event, $props)` /
  `Engine::logExposure($user, $experiment)` forms remain for advanced use.

## 0.8.0

- **BREAKING — `Client` → `Engine` rename + new user-bound `Client`.** The
  heavyweight class that owns the API key, blob cache, fetch, `track()`,
  `see()` and the test/offline factories is now `Shipeasy\Engine` (was
  `Shipeasy\Client`). Rename every `new Client($key)` → `new Engine($key)` and
  `Client::forTesting()`/`Client::fromFile()`/`Client::fromSnapshot()` →
  `Engine::…`. `Engine`'s public surface is otherwise unchanged
  (`overrideFlag`/`overrideConfig`/`overrideExperiment`/`clearOverrides`,
  `onChange`/`refresh`, `track`, `logExposure`, `evaluate`/`bootstrapScriptTag`/
  `i18nScriptTag`, `see`/`seeViolation`/`controlFlowException`, sticky-bucketing
  + `privateAttributes`). The `see()` default-client wiring now hooks off
  `Engine` construction / `configure()`.
- **New ergonomic front door: `configure()` + user-bound `Shipeasy\Client`.**
  - `Shipeasy\configure(string $apiKey, ?callable $attributes = null, array $opts = [])`
    (also `Engine::configure(...)`) builds the **one** global engine
    (first-config-wins), stores an optional `attributes` transform, and fires the
    one-shot fetch so evaluations resolve against real rules without an explicit
    `init()`. `$opts` accepts `baseUrl`/`env`/`disableTelemetry`/`telemetryUrl`/
    `privateAttributes`/`stickyStore`.
  - The new lightweight `Shipeasy\Client` is built with `new Client($user)`
    (array OR object). Its constructor runs the configured `attributes` transform
    once (identity by default — the array IS the attribute map), merges the
    request's `__se_anon_id`, and stores the resulting attribute map. It exposes
    `getFlag($name, $default = false)`, `getFlagDetail($name)`,
    `getConfig($name, $default = null)`, `getExperiment($name, $defaultParams)`
    and `getKillswitch($name, $switchKey = null)` — **no user argument** — each
    forwarding to the global engine with the bound map. It opens no connection
    and starts no poll. Constructing a `Client` before `configure()` throws
    `\RuntimeException`.
- **New `Engine::getKillswitch($name, $switchKey = null)`** reads a kill switch's
  `killed` state (or a named per-key `switches[$switchKey]` override) from the
  flags blob; the bound `Client` forwards to it.

## 0.7.0

- **SSR bootstrap script-tag helpers.** New `Client::evaluate(user)`
  batch-evaluate (every gate/config/experiment → a `['flags', 'configs',
  'experiments', 'killswitches']` payload) plus `bootstrapScriptTag()` and
  `i18nScriptTag()`, which emit the cross-platform declarative `<script>` tags
  carrying the SSR payload as `data-*` attributes. The static `se-bootstrap.js`
  loader hydrates `window.__SE_BOOTSTRAP` and writes the `__se_anon_id` cookie so
  the browser buckets identically to the server. **No SDK key is embedded** in
  the bootstrap tag.

## 0.6.0

- **`see()` structured error reporting.** Added the cross-SDK `see()` errors
  primitive — every handled exception documents its product *consequence*, not
  just its stack. Instance methods on `Client`:
  `see($problem): SeeChain`, `seeViolation(string $name): SeeChain`,
  `controlFlowException(\Throwable $e): ControlFlowChain`. Package-level
  namespaced functions `Shipeasy\see()`, `Shipeasy\seeViolation()`,
  `Shipeasy\controlFlowException()` (autoloaded via composer `autoload.files`)
  route to a default client registered on every `Client` construction (last
  wins; `Client::setDefault()` / `Shipeasy\set_default_client()` to set it
  explicitly). A global `see()` before any client exists logs a warning and
  returns a no-op chain — it never throws.
  - Grammar (dispatch model): `->causesThe($subject)` and `->extras($arr)` are
    chainable setters callable in any order; `->to($outcome)` is the terminal
    that builds the wire event and fire-and-forgets a POST to `/collect`. `to()`
    is idempotent; a chain that never calls `to()` sends nothing.
  - `controlFlowException($e)->because($reason)->extras($arr)` marks the
    throwable expected (recorded in a `WeakMap`-backed registry, since PHP
    exceptions aren't freely mutable) and reports nothing.
  - Wire event: `{type:"error", kind, error_type, message, stack?, subject,
    outcome, extras?, side:"server", env?, sdk_version, ts}`. `kind` is
    `"caught"` for throwables/non-throwable problems and `"violation"` for a
    `Violation`. Extras are sanitized (≤20 keys, 200-char string values, null
    dropped, only string/int/float/bool kept) and the client's private
    attributes are stripped defensively. A per-process `SeeLimiter` (30s dedup,
    25-send cap) bounds network chatter. No-op in `localMode`.
  - Added `Client::VERSION` (`'0.6.0'`) as the single runtime source for the
    `sdk_version` field (composer.json exposes no runtime constant), and stored
    the client `env` so it can be included on the event.

## Unreleased

- **OpenFeature provider.** Added `Shipeasy\OpenFeature\ShipeasyProvider`, an
  implementation of the CNCF OpenFeature PHP provider contract
  (`OpenFeature\interfaces\provider\Provider`, via `AbstractProvider`) that wraps
  an existing `Shipeasy\Client`. `getMetadata()->getName()` is `"shipeasy"`.
  `resolveBooleanValue()` builds a user from the evaluation context (targeting
  key → `user_id`, attributes → user attrs), evaluates the gate, and maps the
  reason: `RULE_MATCH→TARGETING_MATCH`, `DEFAULT→DEFAULT`, `OFF→DISABLED`,
  `OVERRIDE→STATIC`, `FLAG_NOT_FOUND→ERROR`+`FLAG_NOT_FOUND`,
  `CLIENT_NOT_READY→ERROR`+`PROVIDER_NOT_READY`. `resolveString/Integer/Float/
  ObjectValue()` route to `getConfig()`: absent → default + `DEFAULT`, type
  mismatch → default + `TYPE_MISMATCH`, present → value + `TARGETING_MATCH`. Any
  thrown error falls back to the default with a `GENERAL` resolution error.
  `open-feature/sdk` (`^2.0`) is an optional dependency — added to `require-dev`
  and `suggest`; install it in the consuming app.
- **Private attributes.** Added a `privateAttributes` constructor option (a list
  of attribute names). These are usable for targeting but never persisted in
  analytics (LD/Statsig private attributes). The server evaluates locally, so
  private attrs never leave for evaluation at all; the only egress is `/collect`,
  and the listed keys are stripped from every outbound `track()` payload before
  it is POSTed. When all properties are private the event is sent without a
  `properties` field. `stripPrivate()` is exposed for inspection/testing.
- **Manual exposure (`logExposure()`).** Added
  `logExposure(string|array $userOrId, string $experimentName)`. The server is
  stateless and never auto-logs exposures, so call this at the point you present
  the treatment. It re-evaluates the experiment (a bare `user_id` string is
  wrapped as `['user_id' => …]`) and, if the user is enrolled, POSTs a single
  `{type: "exposure", experiment, group, user_id|anonymous_id, ts}` event to
  `/collect`. No-op otherwise (not enrolled, unknown experiment, or test mode).
- **Sticky bucketing.** Added a pluggable `StickyBucketStore` interface
  (`get(string $unit): ?array` / `set(string $unit, string $exp, array $entry)`,
  entry `['g' => group, 's' => salt8]`) plus a built-in `InMemoryStickyStore`,
  wired in through a new `stickyStore` constructor option (and the
  `forTesting()`/`fromSnapshot()`/`fromFile()` factories). When supplied,
  experiment eval — after the holdout, before allocation — returns a unit's
  stored group when the stored 8-char salt prefix still matches (skipping the
  allocation gate, so a shrinking allocation keeps an enrolled unit in); a fresh
  pick is persisted via `set()`. A salt mismatch or a vanished stored group
  re-buckets and overwrites. Absent store ⇒ today's deterministic behaviour (no
  I/O). Mirrors the canonical TypeScript reference SDK and doc 20 §2.
- **Per-experiment `bucketBy`.** Experiment evaluation now honors an optional
  `bucketBy` attribute (JSON `bucketBy`, camelCase): when set, the holdout,
  allocation, and group hashes all bucket on that user attribute (e.g.
  `company_id`) so a whole org moves onto one variant together. A non-empty
  string is used as-is, a number is stringified, and an absent/empty value
  falls back to `user_id ?? anonymous_id` (matching gates). No resolvable unit
  ⇒ not enrolled. Matches the canonical core implementation.
- **Default values on `getFlag()`/`getConfig()`.** Both now take an optional
  default. `getFlag($name, $user, $default = false)` returns the default ONLY
  when the flag cannot be evaluated (client not initialized or gate not in the
  blob) — never when it merely evaluates to false. `getConfig($name, $default = null)`
  returns the default when the config key is absent. Both signatures are
  backward-compatible.
- **Flag evaluation detail.** Added `FlagDetail` (readonly `value`/`reason`) with
  reason constants (`OVERRIDE`, `CLIENT_NOT_READY`, `FLAG_NOT_FOUND`, `OFF`,
  `RULE_MATCH`, `DEFAULT`) and `getFlagDetail($name, $user)`. The reason is
  computed at the boundary without changing the canonical eval; the usage beacon
  fires exactly once per call and never for an override. `getFlag()` now delegates
  to `getFlagDetail()`.
- **Change listeners.** Added `onChange(callable): callable` (returns an
  unsubscribe) plus `refresh()`. Listeners fire only when a refresh applies new
  data (a 200, not a 304), never in local/snapshot mode, each wrapped in
  try/catch. Mainly relevant to long-running runtimes (Swoole/RoadRunner) — under
  PHP-FPM the client is rebuilt per request (see README "Change listeners").
- **Offline file data source.** Added `Client::fromFile($path)` and
  `Client::fromSnapshot($flags, $experiments)` — a no-network client backed by a
  baked blob; evaluations run the real eval against the snapshot and overrides
  apply on top.

- **Local-override test utility.** Added `Client::forTesting()` — a no-network,
  no-key client whose `init()`/`initOnce()` and `track()` are no-ops and
  telemetry is disabled. New override setters `overrideFlag()`,
  `overrideConfig()`, `overrideExperiment()`, and `clearOverrides()` seed
  deterministic values for `getFlag()`/`getConfig()`/`getExperiment()`; an
  override always wins over the fetched blob and also works on a normal client.
  See the README "Testing" section.

## 0.3.0

- **Anonymous bucketing (`__se_anon_id`).** Added `Shipeasy\Identity` — a
  zero-dependency helper whose `Identity::ensure()` reads or mints the shared
  `__se_anon_id` first-party cookie (via `$_COOKIE`/`setcookie()`). Gate/
  experiment evaluations now default to the cookie id as `anonymous_id`, so
  anonymous visitors bucket consistently across server renders and the browser
  with no per-call wiring. Works in plain PHP, WordPress, Laravel, Symfony, Slim.
  Implements the cross-SDK contract in `18-identity-bucketing.md`.
- **Eval fix (no-unit gate rule).** A request with no `user_id`/`anonymous_id`
  now resolves a fully-rolled (100%) gate as **on** instead of always off; a
  fractional gate is still off until a stable unit exists. Matches the
  TypeScript reference SDK. Targeting rules are still evaluated first.

## 0.2.0

- Per-evaluation usage telemetry (fire-and-forget, on by default).

## 0.1.0

- Initial release: feature flags, configs, experiments, metric tracking.
