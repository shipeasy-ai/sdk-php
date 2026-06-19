# Changelog

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
