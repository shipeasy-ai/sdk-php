# Changelog

## Unreleased

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
