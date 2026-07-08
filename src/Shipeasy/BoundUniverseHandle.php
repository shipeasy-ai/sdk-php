<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * A reusable universe handle bound to one universe AND the {@see Client}'s user.
 * Returned by {@see Client::universe()}. Because the user is already bound at
 * `Client` construction, `assign()` takes no argument — it forwards the bound
 * attribute map to {@see Engine::assignUniverse()} and returns an
 * {@see Assignment} (auto-logging a single deduped exposure when enrolled).
 * Never throws.
 */
final class BoundUniverseHandle
{
    /**
     * @param array<string, mixed> $attributes The Client's bound attribute map.
     */
    public function __construct(
        private readonly Engine $engine,
        private readonly string $name,
        private readonly array $attributes,
    ) {}

    /** Assign the bound user within this universe. Never throws. */
    public function assign(): Assignment
    {
        try {
            return $this->engine->assignUniverse($this->name, $this->attributes);
        } catch (\Throwable $e) {
            Logger::warn("Client::universe('$this->name')->assign(): unexpected error, returning not-enrolled — " . $e->getMessage());
            return new Assignment(null, null, []);
        }
    }
}
