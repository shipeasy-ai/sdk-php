<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * Lightweight, user-bound handle — the ergonomic front door.
 *
 * Configure once at process start with {@see configure()} (or
 * {@see Engine::configure()}), then bind a user per request:
 *
 * ```php
 * use function Shipeasy\configure;
 * use Shipeasy\Client;
 *
 * // once, at startup:
 * configure($_ENV['SHIPEASY_SERVER_KEY']);
 *
 * // per request — no key, no user argument on each call:
 * $on = (new Client($currentUser))->getFlag('new_checkout');
 * ```
 *
 * The constructor runs the configured `attributes` transform on $user once
 * (identity by default — the array IS the attribute map), merges the request's
 * anonymous_id exactly like the per-call path, and stores the resulting
 * attribute map. Every method then forwards to the single global {@see Engine}
 * with that bound map — the Client opens no connection and starts no poll.
 *
 * (New in 0.8.0. The heavyweight class formerly named `Client` is now
 * {@see Engine}.)
 */
final class Client
{
    /** The global engine resolved once at construction. */
    private Engine $engine;

    /**
     * The bound attribute map: the transform output merged with the request's
     * anonymous_id (when neither user_id nor anonymous_id was supplied).
     *
     * @var array<string, mixed>
     */
    private array $attributes;

    /**
     * Bind to $user. The configured `attributes` transform maps it to the
     * Shipeasy attribute map (identity by default), then the request's
     * anonymous_id is merged in.
     *
     * @param array<string, mixed>|object $user The customer's own user object.
     *
     * @throws \RuntimeException when {@see configure()} has not been called.
     */
    public function __construct(array|object $user)
    {
        $engine = Engine::getDefault();
        if ($engine === null) {
            throw new \RuntimeException(
                'new Client($user) called before configure($apiKey)'
            );
        }
        $this->engine = $engine;
        $this->attributes = Engine::withBoundAnonId(Engine::applyAttributes($user));
    }

    /** Evaluate the bound user against gate $name; $default when unevaluable. */
    public function getFlag(string $name, bool $default = false): bool
    {
        return $this->engine->getFlag($name, $this->attributes, $default);
    }

    /** Evaluate gate $name and report why it resolved that way. */
    public function getFlagDetail(string $name): FlagDetail
    {
        return $this->engine->getFlagDetail($name, $this->attributes);
    }

    /**
     * Read dynamic config $name (not user-scoped; exposed here for one-stop
     * ergonomics). Returns $default when absent.
     */
    public function getConfig(string $name, mixed $default = null): mixed
    {
        return $this->engine->getConfig($name, $default);
    }

    /** Evaluate experiment $name for the bound user. */
    public function getExperiment(string $name, mixed $defaultParams): ExperimentResult
    {
        return $this->engine->getExperiment($name, $this->attributes, $defaultParams);
    }

    /** Read kill switch $name (optionally a named per-key override). */
    public function getKillswitch(string $name, ?string $switchKey = null): bool
    {
        return $this->engine->getKillswitch($name, $switchKey);
    }
}
