<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * A process-local sticky-bucketing store (array-backed). Handy for tests and
 * single-process / long-running runtimes (Swoole, RoadRunner). Under classic
 * PHP-FPM the store is rebuilt per request, so back stickiness with a durable
 * store (cookie / Redis / database) implementing {@see StickyBucketStore} when
 * assignments must survive across requests.
 */
final class InMemoryStickyStore implements StickyBucketStore
{
    /** @var array<string, array<string, array{g: string, s: string}>> unit => exp => entry */
    private array $store;

    /**
     * @param array<string, array<string, array{g: string, s: string}>> $seed
     */
    public function __construct(array $seed = [])
    {
        $this->store = $seed;
    }

    /**
     * @return array<string, array{g: string, s: string}>|null
     */
    public function get(string $unit): ?array
    {
        return $this->store[$unit] ?? null;
    }

    /**
     * @param array{g: string, s: string} $entry
     */
    public function set(string $unit, string $exp, array $entry): void
    {
        $this->store[$unit][$exp] = $entry;
    }
}
