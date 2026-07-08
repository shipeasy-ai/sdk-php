<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * The result of `universe(name)->assign(user)` — a unit's standing in a universe.
 *
 * A universe is a mutual-exclusion pool, so a unit lands in **at most one**
 * experiment. Never throws: an un-enrolled unit still resolves {@see get()} to
 * the universe defaults (or your fallback). Reading is side-effect free — the
 * single exposure is logged once by `assign()` when the unit is enrolled.
 */
final class Assignment
{
    /**
     * @param string|null $name  The experiment the unit landed in, or null when
     *        not enrolled.
     * @param string|null $group The assigned variant/group name, or null when
     *        not enrolled.
     * @param array<string, mixed> $params Already-merged (universe defaults ⊕
     *        variant override) params when enrolled; defaults-only (or empty)
     *        when not.
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $group,
        private readonly array $params = [],
    ) {}

    /** True iff the unit is enrolled in an experiment in this universe. */
    public function enrolled(): bool
    {
        return $this->group !== null;
    }

    /**
     * Read a resolved param: the assigned variant's override, else the universe
     * default, else $fallback. Works even when not enrolled (the variant layer
     * is absent, so you get `universeDefault ?? fallback`).
     */
    public function get(string $field, mixed $fallback = null): mixed
    {
        return array_key_exists($field, $this->params) ? $this->params[$field] : $fallback;
    }
}
