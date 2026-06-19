<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * Pluggable sticky-bucketing store for the server (doc 20 §2). Keyed by the
 * bucketing unit (the pickIdentifier-resolved identifier); the value is that
 * unit's per-experiment assignments. Absent from the client ⇒ today's
 * deterministic behaviour.
 *
 * Each per-experiment entry is an array `['g' => <group>, 's' => <salt8>]`
 * where `salt8` is the first 8 chars of the experiment salt — changing the
 * salt invalidates the assignment and re-buckets the unit.
 *
 * Use {@see InMemoryStickyStore} for a process-local store, or implement this
 * interface over a cookie / Redis / database for a durable store.
 */
interface StickyBucketStore
{
    /**
     * Return the unit's per-experiment assignments, or null if none are stored.
     *
     * @return array<string, array{g: string, s: string}>|null
     *         exp name => ['g' => group, 's' => salt8]
     */
    public function get(string $unit): ?array;

    /**
     * Persist one experiment assignment for a unit.
     *
     * @param array{g: string, s: string} $entry
     */
    public function set(string $unit, string $exp, array $entry): void;
}
