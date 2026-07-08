<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Engine;
use Shipeasy\InMemoryStickyStore;
use Shipeasy\StickyBucketStore;

/**
 * Coverage for the three parity features:
 *   A — private attributes stripped from track() egress
 *   B — auto-exposure on assign() (deduped, enrolled-only)
 *   C — sticky bucketing (through universe()->assign())
 */
final class StickyAndExposureTest extends TestCase
{
    /** A non-local client that captures /collect POSTs instead of sending them. */
    private function capturingClient(
        array $privateAttributes = [],
        ?StickyBucketStore $stickyStore = null
    ): object {
        return new class ('k', null, 'prod', true, null, $privateAttributes, $stickyStore) extends Engine {
            /** @var array<int, array{path: string, body: array}> */
            public array $posts = [];
            protected function postNonBlocking(string $path, string $body): void
            {
                $this->posts[] = ['path' => $path, 'body' => json_decode($body, true)];
            }
        };
    }

    /** A running experiment with two evenly-weighted groups at 100% allocation. */
    private function expsBlob(string $salt = 'expsalt12345', int $alloc = 10000): array
    {
        return [
            'universes' => ['u1' => ['holdout_range' => null]],
            'experiments' => [
                'checkout_test' => [
                    'universe' => 'u1',
                    'status' => 'running',
                    'salt' => $salt,
                    'allocationPct' => $alloc,
                    'targetingGate' => null,
                    'groups' => [
                        ['name' => 'control', 'weight' => 5000, 'params' => ['c' => 1]],
                        ['name' => 'treatment', 'weight' => 5000, 'params' => ['c' => 2]],
                    ],
                ],
            ],
        ];
    }

    // ---- FEATURE A: private attributes ----

    public function testStripPrivateRemovesListedKeys(): void
    {
        $c = new Engine('k', null, 'prod', true, null, ['email', 'ssn']);
        $out = $c->stripPrivate(['email' => 'a@b.c', 'ssn' => '123', 'plan' => 'pro']);
        $this->assertSame(['plan' => 'pro'], $out);
    }

    public function testStripPrivateNoopWithoutConfig(): void
    {
        $c = new Engine('k', null, 'prod', true);
        $props = ['email' => 'a@b.c', 'plan' => 'pro'];
        $this->assertSame($props, $c->stripPrivate($props));
    }

    public function testTrackStripsPrivateAttributesFromEgress(): void
    {
        $c = $this->capturingClient(['email']);
        $c->track('u1', 'purchase', ['email' => 'a@b.c', 'amount' => 42]);

        $this->assertCount(1, $c->posts);
        $this->assertSame('/collect', $c->posts[0]['path']);
        $event = $c->posts[0]['body']['events'][0];
        $this->assertSame('purchase', $event['event_name']);
        $this->assertSame(['amount' => 42], $event['properties']);
        $this->assertArrayNotHasKey('email', $event['properties']);
    }

    public function testTrackDropsPropertiesWhenAllPrivate(): void
    {
        $c = $this->capturingClient(['email']);
        $c->track('u1', 'ping', ['email' => 'a@b.c']);
        $event = $c->posts[0]['body']['events'][0];
        // All props were private → no `properties` key emitted at all.
        $this->assertArrayNotHasKey('properties', $event);
    }

    // ---- FEATURE B: auto-exposure on assign() ----

    public function testAssignPostsExposureWhenEnrolled(): void
    {
        $c = $this->capturingClient();
        $c->applyData(['gates' => [], 'configs' => []], $this->expsBlob());

        $a = $c->universe('u1')->assign(['user_id' => 'user-42']);

        $this->assertTrue($a->enrolled());
        $this->assertCount(1, $c->posts);
        $event = $c->posts[0]['body']['events'][0];
        $this->assertSame('exposure', $event['type']);
        $this->assertSame('checkout_test', $event['experiment']);
        $this->assertSame('user-42', $event['user_id']);
        $this->assertContains($event['group'], ['control', 'treatment']);
        $this->assertArrayHasKey('ts', $event);
    }

    public function testAssignExposureUsesAnonymousId(): void
    {
        $c = $this->capturingClient();
        $c->applyData(['gates' => [], 'configs' => []], $this->expsBlob());

        $c->universe('u1')->assign(['anonymous_id' => 'anon-9']);
        $event = $c->posts[0]['body']['events'][0];
        $this->assertSame('anon-9', $event['anonymous_id']);
        $this->assertArrayNotHasKey('user_id', $event);
    }

    public function testAssignExposureIsDedupedAcrossRepeatCalls(): void
    {
        $c = $this->capturingClient();
        $c->applyData(['gates' => [], 'configs' => []], $this->expsBlob());

        for ($i = 0; $i < 5; $i++) {
            $c->universe('u1')->assign(['user_id' => 'user-42']);
        }
        // Same (uid, experiment, group) → a single exposure over 5 assigns.
        $this->assertCount(1, $c->posts);
    }

    public function testAssignNoExposureWhenNotEnrolled(): void
    {
        // allocation 0 → nobody enrolled → no exposure posted.
        $c = $this->capturingClient();
        $c->applyData(['gates' => [], 'configs' => []], $this->expsBlob('salt', 0));

        $a = $c->universe('u1')->assign(['user_id' => 'user-42']);
        $this->assertFalse($a->enrolled());
        $this->assertCount(0, $c->posts);
    }

    public function testAssignNoExposureForUnknownUniverse(): void
    {
        $c = $this->capturingClient();
        $c->applyData(['gates' => [], 'configs' => []], $this->expsBlob());
        $a = $c->universe('does_not_exist')->assign(['user_id' => 'user-42']);
        $this->assertFalse($a->enrolled());
        $this->assertCount(0, $c->posts);
    }

    public function testAssignNoOpInLocalMode(): void
    {
        $c = Engine::forTesting();
        // forTesting can't capture posts, but it must simply not throw / not send.
        $a = $c->universe('whatever')->assign(['user_id' => 'user-42']);
        $this->assertFalse($a->enrolled());
    }

    // ---- FEATURE C: sticky bucketing ----

    public function testInMemoryStoreGetSet(): void
    {
        $store = new InMemoryStickyStore();
        $this->assertNull($store->get('u1'));
        $store->set('u1', 'exp', ['g' => 'control', 's' => 'salt1234']);
        $this->assertSame(['exp' => ['g' => 'control', 's' => 'salt1234']], $store->get('u1'));
    }

    public function testFreshPickPersistsAssignment(): void
    {
        $store = new InMemoryStickyStore();
        $c = Engine::fromSnapshot(['gates' => [], 'configs' => []], $this->expsBlob(), $store);

        $a = $c->universe('u1')->assign(['user_id' => 'u1']);
        $this->assertTrue($a->enrolled());

        $entry = $store->get('u1')['checkout_test'];
        $this->assertSame($a->group, $entry['g']);
        $this->assertSame(substr('expsalt12345', 0, 8), $entry['s']);
    }

    public function testStickyEntrySticksThroughAllocationDrop(): void
    {
        // Seed an enrolled unit with the treatment group + correct salt prefix.
        $salt8 = substr('expsalt12345', 0, 8);
        $store = new InMemoryStickyStore(['u1' => ['checkout_test' => ['g' => 'treatment', 's' => $salt8]]]);

        // Allocation now 0 — a deterministic eval would drop u1. Sticky keeps it.
        $c = Engine::fromSnapshot(['gates' => [], 'configs' => []], $this->expsBlob('expsalt12345', 0), $store);

        $a = $c->universe('u1')->assign(['user_id' => 'u1']);
        $this->assertTrue($a->enrolled());
        $this->assertSame('treatment', $a->group);
        $this->assertSame(2, $a->get('c'));
    }

    public function testSaltMismatchRebuckets(): void
    {
        // Stored salt prefix no longer matches → the stored group is ignored and
        // the unit is re-bucketed + overwritten with the new salt prefix.
        $store = new InMemoryStickyStore(['u1' => ['checkout_test' => ['g' => 'treatment', 's' => 'OLDSALT0']]]);
        $c = Engine::fromSnapshot(['gates' => [], 'configs' => []], $this->expsBlob('newsalt99999'), $store);

        $a = $c->universe('u1')->assign(['user_id' => 'u1']);
        $this->assertTrue($a->enrolled());

        $entry = $store->get('u1')['checkout_test'];
        $this->assertSame(substr('newsalt99999', 0, 8), $entry['s']);
        $this->assertSame($a->group, $entry['g']);
    }

    public function testStoredGroupGoneRebuckets(): void
    {
        // Salt matches, but the stored group name is no longer in the experiment
        // → fall through to a fresh pick + overwrite (a real group is returned).
        $salt8 = substr('expsalt12345', 0, 8);
        $store = new InMemoryStickyStore(['u1' => ['checkout_test' => ['g' => 'ghost', 's' => $salt8]]]);
        $c = Engine::fromSnapshot(['gates' => [], 'configs' => []], $this->expsBlob(), $store);

        $a = $c->universe('u1')->assign(['user_id' => 'u1']);
        $this->assertTrue($a->enrolled());
        $this->assertContains($a->group, ['control', 'treatment']);
        $this->assertSame($a->group, $store->get('u1')['checkout_test']['g']);
    }

    public function testStickyIsStableAcrossCalls(): void
    {
        $store = new InMemoryStickyStore();
        $c = Engine::fromSnapshot(['gates' => [], 'configs' => []], $this->expsBlob(), $store);

        $first = $c->universe('u1')->assign(['user_id' => 'u1'])->group;
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, $c->universe('u1')->assign(['user_id' => 'u1'])->group);
        }
    }

    public function testNoStoreIsDeterministicAndDoesNotPersist(): void
    {
        // Absent store ⇒ no behaviour change, no side effects.
        $c = Engine::fromSnapshot(['gates' => [], 'configs' => []], $this->expsBlob());
        $a = $c->universe('u1')->assign(['user_id' => 'u1']);
        $this->assertTrue($a->enrolled());
        $this->assertContains($a->group, ['control', 'treatment']);
    }
}
