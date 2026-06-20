<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * Package-level see() facade backed by the default client (the last-constructed
 * Client, the server-SDK analog of TS's shipeasy({key}) configure call). Use
 * $client->see(...) to target a specific client.
 *
 * These functions are autoloaded via composer "autoload.files".
 */

/** The SDK version string (mirror of Client::VERSION as a namespaced const). */
const VERSION = Client::VERSION;

/**
 * Register the client backing the package-level see() functions. Called
 * automatically when a Client is constructed (last wins).
 */
function set_default_client(Client $client): void
{
    Client::setDefault($client);
}

/**
 * Report a caught throwable (or thrown non-throwable) via the default client.
 * Before any client exists this logs a warning and returns a no-op chain — it
 * NEVER throws.
 */
function see(mixed $problem): SeeChain
{
    $client = Client::getDefault();
    if ($client === null) {
        @trigger_error(
            '[shipeasy] see() called before a client was created — error dropped',
            E_USER_WARNING
        );
        return new SeeChain($problem, static function (): void {
        });
    }
    return $client->see($problem);
}

/** Report a non-exception problem via the default client. */
function seeViolation(string $name): SeeChain
{
    $client = Client::getDefault();
    if ($client === null) {
        @trigger_error(
            '[shipeasy] seeViolation() called before a client was created — error dropped',
            E_USER_WARNING
        );
        return new SeeChain(new Violation($name), static function (): void {
        });
    }
    return $client->seeViolation($name);
}

/**
 * Mark an exception as expected control flow (reports nothing). Works without a
 * client — it only stamps the throwable.
 */
function controlFlowException(\Throwable $err): ControlFlowChain
{
    return new ControlFlowChain($err);
}
