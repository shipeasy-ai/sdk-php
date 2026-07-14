<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * The result of `universe(name)->assign(user)` — a unit's standing in a universe.
 *
 * A universe is a mutual-exclusion pool, so a unit lands in **at most one**
 * experiment. Never throws: an un-enrolled unit still resolves {@see get()} to
 * the universe defaults (or your fallback).
 *
 * Exposure is logged **on read** (spec step 7): the single exposure fires the
 * first time an enrolled unit's param is actually read via {@see get()}, not at
 * `assign()` time — so an assignment that is computed but never read logs
 * nothing. Deduped per process; the durable per-(unit, experiment, group) dedup
 * lives server-side. Pass `exposure: false` to read without logging (peek).
 */
final class Assignment
{
    private bool $exposed = false;

    /**
     * @param string|null $name  The experiment the unit landed in, or null when
     *        not enrolled.
     * @param string|null $group The assigned variant/group name, or null when
     *        not enrolled.
     * @param array<string, mixed> $params Already-merged (universe defaults ⊕
     *        variant override) params when enrolled; defaults-only (or empty)
     *        when not.
     * @param (callable(): void)|null $onExpose Fires the single exposure the
     *        first time an enrolled param is read; null when not enrolled
     *        (nothing to expose). Deduped downstream.
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $group,
        private readonly array $params = [],
        private $onExpose = null,
    ) {}

    /**
     * True iff the unit is enrolled in an experiment in this universe. Reading
     * it does NOT log an exposure (only {@see get()} of a param does).
     */
    public function enrolled(): bool
    {
        return $this->group !== null;
    }

    /**
     * Read a resolved param: the assigned variant's override, else the universe
     * default, else $fallback. Works even when not enrolled (the variant layer
     * is absent, so you get `universeDefault ?? fallback`). The first enrolled
     * read logs the single exposure; pass `exposure: false` to suppress it
     * (peek).
     */
    public function get(string $field, mixed $fallback = null, bool $exposure = true): mixed
    {
        // On-read exposure: the first param read of an enrolled assignment logs
        // one exposure, unless the caller opted out with `exposure: false`.
        if ($exposure && !$this->exposed && $this->onExpose !== null) {
            $this->exposed = true;
            ($this->onExpose)();
        }

        return array_key_exists($field, $this->params) ? $this->params[$field] : $fallback;
    }
}
