# CLAUDE.md — shipeasy/shipeasy (PHP)

Guidance for AI agents (and humans) working in this repository.

## What this is

`shipeasy/shipeasy` — the **server** SDK for [Shipeasy](https://shipeasy.ai):
feature flags, dynamic configs, kill switches, A/B experiments, metric tracking,
`see()` error reporting, and SSR/i18n helpers. Server-key only; never embed in a
browser. PSR-4 under `src/Shipeasy/` (+ namespaced functions in
`src/Shipeasy/functions.php`); tests under `tests/` (run with `phpunit`). PHP is
request-scoped: `configure()` fetches once per request — there is no background
poll (long-running runtimes like Swoole/RoadRunner refresh on a schedule).

## The documented public surface (this is a contract)

Users are taught exactly **two** things, and the docs must never drift from them:

1. **`Shipeasy\configure()`** — and its siblings `Shipeasy\configureForTesting()` /
   `Shipeasy\configureForOffline()` — for setup.
2. **`new Shipeasy\Client($user)`** — the cheap, user-bound handle for *all* reads
   (`getFlag` / `getFlagDetail` / `getConfig` / `getKillswitch` / `track`, plus
   universe assignment via `universe(name)->assign()`).

Plus the package-level functions that let users avoid the heavyweight object:
`Shipeasy\overrideFlag` / `overrideConfig` / `overrideExperiment` /
`clearOverrides`, `onChange`, `bootstrapScriptTag` / `i18nScriptTag`, the
global-form `ShipeasyProvider` (OpenFeature), and the `see()` family.

**The `Engine` class is an internal detail. Do NOT document it.** It stays public
for advanced/back-compat use, but no page, snippet, skill, or the README should
tell a user to construct or call an `Engine`. New user-facing capability should
get a `configure`-style or package-level affordance, then be documented through it.

## HARD RULE: change the SDK → update the docs in the SAME change

`docs/` is the published, user-facing source of truth (rendered at
<https://shipeasy-ai.github.io/sdk-php/> and ingested by the Shipeasy CLI/MCP
`docs` tooling and the central docs portal). Any change to the SDK's **public API
or behaviour** updates the relevant `docs/pages/*.md`, the matching
`docs/snippets/**`, and `docs/skill/SKILL.md` in the same commit; new
page/snippet/placeholder → also `docs/manifest.json`. See
[`docs/CLAUDE.md`](docs/CLAUDE.md) for structure and conventions.

**`README.md` is generated — do not hand-edit it.** It is assembled from the docs
by `scripts/gen-readme.php`. After editing `docs/`, run:

```bash
composer gen-readme   # or: php scripts/gen-readme.php
```

CI (`.github/workflows/tests.yml`) re-runs it and fails if `README.md` drifts.

## Versioning & release

- Bump **both** `Engine::VERSION` (`src/Shipeasy/Engine.php`, sent on every
  `see()` event) and the `version` in `composer.json`, and add a `CHANGELOG.md`
  entry.
- Publishing is **push-to-`main`**: the publish workflow self-tags `v$VERSION` and
  Packagist serves it. A version-bumped push to `main` IS the release.

## Checks before you commit

- `composer install && vendor/bin/phpunit` (the suite is hermetic — no network).
  CI runs PHP 8.1–8.4 via `tests.yml`. Locally `php -l` every file you touch.
- New public behaviour ships with a test.
- Docs updated per the hard rule; `docs/manifest.json` stays valid JSON and every
  path it lists exists.
- `composer gen-readme` and commit the result (CI checks it's in sync).
