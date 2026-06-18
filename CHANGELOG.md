# Changelog

## Unreleased

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
