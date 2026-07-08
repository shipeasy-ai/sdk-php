<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * A reusable handle bound to one universe, returned by
 * {@see Engine::universe()}. `assign($user)` picks the ≤1 experiment the unit is
 * pooled into within the universe and auto-logs a single (deduped) exposure when
 * enrolled. See {@see Engine::assignUniverse()}.
 *
 * (The user-bound {@see Client} exposes its own zero-arg `universe()->assign()`
 * that forwards the bound attribute map — see {@see BoundUniverseHandle}.)
 */
final class UniverseHandle
{
    public function __construct(
        private readonly Engine $engine,
        private readonly string $name,
    ) {}

    /**
     * Assign $user within this universe. Never throws.
     *
     * @param array<string, mixed> $user
     */
    public function assign(array $user): Assignment
    {
        return $this->engine->assignUniverse($this->name, $user);
    }
}
