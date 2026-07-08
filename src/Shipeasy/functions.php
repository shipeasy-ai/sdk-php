<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * Package-level facade. The ergonomic front door is configure() + the
 * user-bound Client; the see() functions are backed by the default engine (the
 * configured/last-constructed Engine, the server-SDK analog of TS's
 * shipeasy({key}) configure call). Use $engine->see(...) to target a specific
 * engine.
 *
 * These functions are autoloaded via composer "autoload.files".
 */

/** The SDK version string (mirror of Engine::VERSION as a namespaced const). */
const VERSION = Engine::VERSION;

/**
 * Configure the process-wide Shipeasy engine (first-config-wins) and store the
 * optional attributes transform used by every user-bound {@see Client}. Fires
 * the one-shot fetch so `new Client($user)->getFlag(...)` resolves against real
 * rules without an explicit init call.
 *
 * ```php
 * use function Shipeasy\configure;
 * use Shipeasy\Client;
 *
 * configure($_ENV['SHIPEASY_SERVER_KEY'], fn ($u) => [
 *     'user_id' => $u->id,
 *     'plan'    => $u->plan,
 * ]);
 *
 * $on = (new Client($currentUser))->getFlag('new_checkout');
 * ```
 *
 * @param string $apiKey The Shipeasy **server** key.
 * @param callable|null $attributes (yourUser) -> attributeMap; default identity.
 * @param array<string, mixed> $opts Extra Engine options (baseUrl, env,
 *        disableTelemetry, telemetryUrl, privateAttributes, stickyStore,
 *        logLevel). `logLevel` is one of 'silent'|'error'|'warn'|'info'|'debug'
 *        (default 'warn') and sets the SDK's own diagnostic verbosity.
 */
function configure(string $apiKey, ?callable $attributes = null, array $opts = []): Engine
{
    return Engine::configure($apiKey, $attributes, $opts);
}

/**
 * Configure Shipeasy in **test mode** — a drop-in sibling of {@see configure()}
 * with no network, ever (no api key needed). Seed `flags` (`name => bool`),
 * `configs` (`name => value`), `experiments` (`name => [group, params]`), and an
 * optional `attributes` transform, then read through `new Client($user)`.
 * REPLACES any prior config so tests can reconfigure between cases.
 *
 * @param array<string, mixed> $opts
 */
function configureForTesting(array $opts = []): Engine
{
    return Engine::configureForTesting($opts);
}

/**
 * Configure Shipeasy **offline** — evaluate the REAL rules from an in-memory
 * `snapshot` (`['flags' => ..., 'experiments' => ...]`) or a JSON `path`, with no
 * network. Optional `flags`/`configs`/`experiments` overrides layer on top.
 * REPLACES any prior config.
 *
 * @param array<string, mixed> $opts
 */
function configureForOffline(array $opts = []): Engine
{
    return Engine::configureForOffline($opts);
}

/** @internal Resolve the configured global engine or throw a clear error. */
function _requireGlobal(string $fn): Engine
{
    $engine = Engine::getDefault();
    if ($engine === null) {
        throw new \RuntimeException(
            "[shipeasy] $fn() called before Shipeasy\\configure() (or a configureFor* sibling)"
        );
    }
    return $engine;
}

/**
 * Force `getFlag($name)` -> $value on the spot, for the current configuration —
 * a quick in-test override layered on top of whatever configureForTesting /
 * configureForOffline (or configure) set up. Wins over the blob until
 * {@see clearOverrides()}.
 */
function overrideFlag(string $name, bool $value): void
{
    _requireGlobal('overrideFlag')->overrideFlag($name, $value);
}

/** Force `getConfig($name)` -> $value on the spot (see {@see overrideFlag()}). */
function overrideConfig(string $name, mixed $value): void
{
    _requireGlobal('overrideConfig')->overrideConfig($name, $value);
}

/**
 * Force `getExperiment($name)` to report enrolment in $group with $params on the
 * spot (see {@see overrideFlag()}).
 */
function overrideExperiment(string $name, string $group, mixed $params): void
{
    _requireGlobal('overrideExperiment')->overrideExperiment($name, $group, $params);
}

/**
 * Drop every on-the-spot flag/config/experiment override — INCLUDING the seed
 * from configureForTesting (test mode has no blob beneath, so everything reverts
 * to empty-blob defaults). Under configureForOffline the snapshot remains.
 */
function clearOverrides(): void
{
    _requireGlobal('clearOverrides')->clearOverrides();
}

/**
 * Register a change listener on the configured global engine. Returns an
 * unsubscribe callable. (PHP is request-scoped and has no background poll, so
 * this fires only if a long-running runtime calls the engine's refresh on a
 * schedule.)
 */
function onChange(callable $fn): callable
{
    return _requireGlobal('onChange')->onChange($fn);
}

/**
 * Return the cross-platform SSR bootstrap `<script>` tag for a request (no key
 * embedded), via the configured global engine — call {@see configure()} first.
 *
 * @param array<string, mixed> $user
 * @param array<string, mixed> $opts
 */
function bootstrapScriptTag(array $user, array $opts = []): string
{
    return _requireGlobal('bootstrapScriptTag')->bootstrapScriptTag($user, $opts);
}

/**
 * Return the i18n loader `<script>` tag (public client key) for SSR, via the
 * configured global engine — call {@see configure()} first.
 *
 * @param array<string, mixed> $opts
 */
function i18nScriptTag(string $clientKey, string $profile = 'en:prod', array $opts = []): string
{
    return _requireGlobal('i18nScriptTag')->i18nScriptTag($clientKey, $profile, $opts);
}

/**
 * Register the engine backing the package-level see() functions and the
 * user-bound Client. Called automatically when an Engine is constructed (last
 * wins); configure() uses first-config-wins.
 */
function set_default_client(Engine $engine): void
{
    Engine::setDefault($engine);
}

/**
 * Report a caught throwable (or thrown non-throwable) via the default engine.
 * Before any engine exists this logs a warning and returns a no-op chain — it
 * NEVER throws.
 */
function see(mixed $problem): SeeChain
{
    $engine = Engine::getDefault();
    if ($engine === null) {
        Logger::warn('see() called before an engine was created — error dropped');
        return new SeeChain($problem, static function (): void {
        });
    }
    return $engine->see($problem);
}

/** Report a non-exception problem via the default engine. */
function seeViolation(string $name): SeeChain
{
    $engine = Engine::getDefault();
    if ($engine === null) {
        Logger::warn('seeViolation() called before an engine was created — error dropped');
        return new SeeChain(new Violation($name), static function (): void {
        });
    }
    return $engine->seeViolation($name);
}

/**
 * Mark an exception as expected control flow (reports nothing). Works without an
 * engine — it only stamps the throwable.
 */
function controlFlowException(\Throwable $err): ControlFlowChain
{
    return new ControlFlowChain($err);
}
