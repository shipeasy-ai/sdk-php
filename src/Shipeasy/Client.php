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
        try {
            return $this->engine->getFlag($name, $this->attributes, $default);
        } catch (\Throwable $e) {
            Logger::error("Client::getFlag('$name'): unexpected error, returning default — " . $e->getMessage());
            return $default;
        }
    }

    /** Evaluate gate $name and report why it resolved that way. */
    public function getFlagDetail(string $name): FlagDetail
    {
        try {
            return $this->engine->getFlagDetail($name, $this->attributes);
        } catch (\Throwable $e) {
            Logger::error("Client::getFlagDetail('$name'): unexpected error, treating as unevaluable — " . $e->getMessage());
            return new FlagDetail(false, FlagDetail::CLIENT_NOT_READY);
        }
    }

    /**
     * Read dynamic config $name (not user-scoped; exposed here for one-stop
     * ergonomics). Returns $default when absent.
     */
    public function getConfig(string $name, mixed $default = null): mixed
    {
        try {
            return $this->engine->getConfig($name, $default);
        } catch (\Throwable $e) {
            Logger::error("Client::getConfig('$name'): unexpected error, returning default — " . $e->getMessage());
            return $default;
        }
    }

    /**
     * The universe-first experiment read entry point for the bound user:
     * `(new Client($user))->universe('checkout')->assign()`. A universe is a
     * mutual-exclusion pool, so the unit lands in **at most one** experiment.
     * Returns a reusable handle whose `assign()` takes no argument (the user is
     * already bound) and returns an {@see Assignment} — auto-logging a single
     * deduped exposure when enrolled. Never throws.
     */
    public function universe(string $name): BoundUniverseHandle
    {
        return new BoundUniverseHandle($this->engine, $name, $this->attributes);
    }

    /** Read kill switch $name (optionally a named per-key override). */
    public function getKillswitch(string $name, ?string $switchKey = null): bool
    {
        try {
            return $this->engine->getKillswitch($name, $switchKey);
        } catch (\Throwable $e) {
            Logger::error("Client::getKillswitch('$name'): unexpected error, treating as not killed — " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record a conversion / custom event for the bound user. No user argument —
     * the unit is derived from the bound attribute map (user_id, else
     * anonymous_id). Delegates to {@see Engine::track()}. The low-level
     * Engine::track($userId, $event, $props) remains for advanced use.
     *
     * @param array<string, mixed> $props
     */
    public function track(string $event, array $props = []): void
    {
        try {
            $id = (string) ($this->attributes['user_id'] ?? $this->attributes['anonymous_id'] ?? '');
            $this->engine->track($id, $event, $props);
        } catch (\Throwable $e) {
            Logger::warn("Client::track('$event'): event dropped — " . $e->getMessage());
        }
    }

}
