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
 *        disableTelemetry, telemetryUrl, privateAttributes, stickyStore).
 */
function configure(string $apiKey, ?callable $attributes = null, array $opts = []): Engine
{
    return Engine::configure($apiKey, $attributes, $opts);
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
        @trigger_error(
            '[shipeasy] see() called before an engine was created — error dropped',
            E_USER_WARNING
        );
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
        @trigger_error(
            '[shipeasy] seeViolation() called before an engine was created — error dropped',
            E_USER_WARNING
        );
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
