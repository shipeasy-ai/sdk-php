# Changelog

## 0.19.0 — 2026-07-19

### Carry the server-identified user on the SSR bootstrap tag

- **`bootstrapScriptTag()` now emits `data-user`** — the request's identified
  traits (the `$user` it already evaluates, minus `anonymous_id`, dropping
  null/empty values) as JSON on the bootstrap `<script>`. The browser SDK reads it
  and adopts the identity on first paint, so a PHP-backend + JS-frontend app
  renders as the same identified user the server bucketed — killing the
  anon→identified flip. A purely anonymous request (only `anonymous_id`, or an
  empty user) emits no `data-user`, so no PII rides the tag. `anonymous_id`
  continues to ride `data-anon-id`. Mirrors `@shipeasy/sdk` 7.9.0 and the Python
  SDK 0.20.0; see `experiment-platform/18-identity-bucketing.md`.

## 0.18.1 — 2026-07-19

### Local gate eval honors the gatekeeper `stack`

- **`Eval_::evalGate` now evaluates a gate's ordered gatekeeper `stack`** instead
  of only the flat `rules` + `rolloutPct` columns. For a modern gate the flat
  fields are lossy: a whitelist condition at 100% followed by a 0% public rollout
  flattens to `rules:[project_id in [...]]`, `rolloutPct:0`, which the flat path
  wrongly read as "matches the whitelist AND is in a 0% bucket" = never true. When
  a `stack` is present, entries are tried top-to-bottom and the gate passes on the
  first whose rules match (all, or any with `pass:'any'`) AND whose per-entry
  rollout bucket hits — with per-entry `bucketBy`, `salt`, and time-based `ramp`
  support. Mirrors `@shipeasy/core`'s `evalGatekeeper`. A stack-less gate keeps the
  exact legacy flat behavior.

## 0.18.0 — 2026-07-13

### see(): inline extras on `->to`, ambient per-request extras, no ordering footgun

- **`->to($outcome, $extras)`** — the terminal now takes the extras inline, e.g.
  `see($e)->causesThe('checkout')->to('use cached prices', ['order_id' => $oid])`.
  Equivalent to a final `->extras(...)`; folds under any earlier `->extras` (later
  wins). So there is no longer an order to remember.
- **An `->extras` chained AFTER `->to` no longer fatals.** Previously `->to`
  returned `void`, so a trailing `->extras(...)` raised a `TypeError` — inside your
  catch block, the worst place. `->to` now returns the chain; a post-`->to`
  `->extras` is ignored with a warning (the report already shipped). Use
  `->to($outcome, $extras)` or `Shipeasy\addExtras()` for late context.
- **`Shipeasy\addExtras(...)` / `Shipeasy\clearExtras()`** — an ambient
  per-request extras buffer. Call `Shipeasy\addExtras(['order_id' => $id, 'tenant' => $t])`
  from anywhere (any layer, not just the catch) and every `see()` report that
  fires later in the same request merges it in. A chained `->extras` / `->to`
  extra overrides an ambient key of the same name; ambient extras are sanitized
  and private-attribute-stripped like any other. **PHP is share-nothing per
  request:** under PHP-FPM / mod_php the buffer resets per request automatically;
  under a long-running runtime (Swoole / RoadRunner / a resident worker loop) the
  app MUST call `Shipeasy\clearExtras()` at request end so context never leaks
  into the next request.

## 0.17.0 — 2026-07-08

### Exposure logs on read; durable forced-but-gated overrides

- **Server exposure now fires on read, not on `assign()`.** `universe($name)->assign()`
  is side-effect free — the exposure is logged the **first time** you read a param
  via `$assignment->get(...)` on an enrolled unit. Exposure is deduped per process
  **and** durably per `(unit, experiment, group)` server-side, so repeated reads
  (or a re-`assign()` then re-`get()`) emit exactly one exposure.
- **New peek opt-out on `get()`:** `get(string $field, mixed $fallback = null, bool $exposure = true)`
  — pass `exposure: false` to read a param **without** logging an exposure.
- **Durable forced-but-gated overrides.** The resolver now honours durable **ID
  overrides** and **cohort/gate overrides** that are forced but still gated: a
  matched override pins the group only if the unit passes targeting and isn't held
  out, and ID overrides beat cohort overrides. Running experiments are
  byte-identical — the new resolution order rides `hash_version: 3`. No new
  user-facing SDK API (consumed via the experiments blob).

## 0.16.1 — 2026-07-08

### Laravel config pins network egress to production

The published `config/shipeasy.php` now defaults `network_enabled` to
`app()->isProduction()` (was `null`), so a scaffolded Laravel app is fully active
in production and quiet elsewhere, stated explicitly. Override with
`SHIPEASY_NETWORK_ENABLED`, or pass `null` to let the SDK infer production at
runtime (safer under `config:cache`).

## 0.16.0 — 2026-07-08

### Environment-derived network & telemetry (egress) defaults

The SDK is now **quiet outside production**: on a dev machine or in CI it makes
**no** outbound request — rule-blob fetch, `track`, exposures, `see()` reports,
internal error reporting, and usage telemetry are all off — until you opt in. In
production it behaves exactly as before (fully on).

- **New `configure()` option `isNetworkEnabled`** — a master switch for **all**
  outbound requests. When off the SDK is fully offline: reads return your in-code
  defaults / overrides and nothing is sent. `null` (the default) ⇒
  environment-derived.
- **`disableTelemetry` is now environment-derived too** — it defaults to on in
  production and off everywhere else (and is always off when `isNetworkEnabled`
  is off). Passing an explicit `true`/`false` still wins.
- **New `Shipeasy\Env::isProductionEnv()` helper** decides "is this production"
  with this precedence: a native env var — `SHIPEASY_ENV`, then `APP_ENV`
  (Laravel/Symfony), then `ENV` (`production`/`prod`, case-insensitive ⇒ prod;
  any other present value ⇒ not prod) — else the SDK's own `env` option (defaults
  to `'prod'`, so a real production deploy stays on).
- **Laravel:** new `network_enabled` config key (env `SHIPEASY_NETWORK_ENABLED`)
  maps to `isNetworkEnabled`. Because Laravel sets `APP_ENV`, a `local`/`testing`
  app is quiet by default with no extra config.

**Behaviour change — how to restore the old always-on egress:** set
`SHIPEASY_ENV=production` (or `APP_ENV=production`) in the environment, or pass
`'isNetworkEnabled' => true` to `configure()`. Explicitly-passed values always
override the environment default; `configureForTesting()` / `configureForOffline()`
remain offline regardless.

## 0.15.0 — 2026-07-08

### Breaking — experiments are now read by universe, not by name

The whole experiment read surface is replaced. A **universe is a mutual-exclusion
pool**: a unit is enrolled in **at most one** experiment in it, so you ask a
universe for an assignment instead of naming an experiment. `Engine::getExperiment`,
`Engine::logExposure`, and `Client::logExposure` are **removed**. (This is a 0.x
minor bump but the change IS breaking — pin exactly if you cannot migrate.)

```php
// Before (removed):
$exp = $client->getExperiment('checkout_color', ['button_color' => 'red']);
if ($exp->inExperiment && $exp->params['button_color'] === 'green') { … }
$client->logExposure('checkout_color');

// After — bound Client (user pre-bound at construction):
$a = $client->universe('checkout')->assign();
if ($a->get('button_color') === 'green') { … }

// After — Engine form (pass the user explicitly):
$a = $engine->universe('checkout')->assign(['user_id' => 'u1']);
```

- **`universe($name)->assign($user)`** (Engine) / **`->universe($name)->assign()`**
  (bound `Client`, forwards the bound attributes) returns an `Assignment`:
  - `->name` — the experiment the unit landed in, or `null` when not enrolled.
  - `->group` — the assigned variant, or `null` when not enrolled.
  - `->enrolled()` — bool (`group !== null`).
  - `->get($field, $fallback = null)` — resolves **variant override ?? universe
    default ?? fallback**. Works even when not enrolled (you get the universe
    default), because the universe now owns the param schema + defaults. There is
    no `$defaultParams` argument anymore.
- **Auto-exposure.** `assign()` logs a single exposure to `/collect` when the unit
  is enrolled, deduped per process (bounded set, cleared at ~5000 entries). The
  manual `logExposure` primitive is gone — reading *is* the exposure. No-op in
  test/offline mode.
- **Mutual exclusion (pooled assignment via `hashVersion`/`poolOffsetBp`/
  `poolSizeBp`), per-experiment holdout gates (`holdoutGate`), reserved headroom
  (`reservedHeadroomBp`), and universe-default ⊕ variant param merge** are now
  honoured by local eval, matching the edge. A universe param schema
  (`param_schema`) supplies the defaults every variant inherits.
- **Bootstrap payload.** `Engine::evaluate()` now carries a top-level `universes`
  defaults map and a `universe` field per experiment entry; enrolled `params` are
  the MERGED (universe defaults ⊕ variant) params.
- The internal `overrideExperiment` test seam is kept (still experiment-keyed); an
  override surfaces through `universe($name)->assign()` when the experiment exists
  in the loaded blob.

Migration: replace each `getExperiment('<exp>', $defaults)` with
`universe('<the experiment's universe>')->assign()` and read fields via
`->get('field', $fallbackFromDefaults)`; delete `logExposure()` calls (exposure is
automatic on `assign()`).

## 0.14.0 — 2026-07-08

### Added

- **Internal SDK-error self-reporting.** When a fail-safe read guard swallows one
  of the SDK's OWN internal errors — `getFlag` / `getFlagDetail` / `getConfig` /
  `getKillswitch` / `getExperiment` catching an internal invariant violation and
  returning its documented safe default — the SDK now also ships a structured
  error event to **Shipeasy's own project** (a baked-in `/collect` destination +
  public write-only client key), so the SDK team can track SDK-internal failures
  across every app the SDK runs in. This is deliberately distinct from the
  customer-facing `see()` path: internal errors never authenticate with your key
  and never land in your Errors tab. The report is fire-and-forget, never blocks,
  never throws into caller code, and is deduped/rate-limited by the same
  `SeeLimiter` as `see()`. The wire event reuses the existing see-event builder,
  so the consequence is stable (`subject` = the operation name, `outcome` =
  `"returned a safe default"`, `extras.sdk` = `"php"`) and repeated occurrences of
  the same bug fold into one issue. Until the real ingest key is provisioned the
  channel is fully inert (it ships with an inert placeholder key).
- **New `disableInternalErrorReporting` option.** `configure()` (and
  `Engine::__construct`) accept `disableInternalErrorReporting` to opt out of the
  channel above — default OFF (reporting ON); forced OFF in
  `configureForTesting` / `configureForOffline` / test/offline mode. The Laravel
  config gains `disable_internal_error_reporting`
  (`SHIPEASY_DISABLE_INTERNAL_ERROR_REPORTING`, default `false`), wired through the
  service provider.

## 0.13.1 — 2026-07-07

### Fixed

- **Default API host now resolves.** The default `baseUrl` pointed at the
  unregistered domain `https://edge.shipeasy.dev`, so every `configure()` fetch
  and every `getFlag`/`getConfig`/`getExperiment`/`track`/`see()` call failed
  with a DNS error unless `baseUrl` was set explicitly. Corrected to the real
  edge origin `https://api.shipeasy.ai` — the host the docs, CLI, and curl
  snippets already use. Explicit `baseUrl` overrides are unaffected.

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
