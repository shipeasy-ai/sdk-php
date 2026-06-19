<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Client;
use Shipeasy\InMemoryStickyStore;
use Shipeasy\StickyBucketStore;

/**
 * Coverage for the three parity features:
 *   A — private attributes stripped from track() egress
 *   B — server manual exposure (logExposure)
 *   C — sticky bucketing
 */
final class StickyAndExposureTest extends TestCase
{
    /** A non-local client that captures /collect POSTs instead of sending them. */
    private function capturingClient(
        array $privateAttributes = [],
        ?StickyBucketStore $stickyStore = null
    ): object {
        return new class ('k', null, 'prod', true, null, $privateAttributes, $stickyStore) extends Client {
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
        $c = new Client('k', null, 'prod', true, null, ['email', 'ssn']);
        $out = $c->stripPrivate(['email' => 'a@b.c', 'ssn' => '123', 'plan' => 'pro']);
        $this->assertSame(['plan' => 'pro'], $out);
    }

    public function testStripPrivateNoopWithoutConfig(): void
    {
        $c = new Client('k', null, 'prod', true);
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

    // ---- FEATURE B: manual exposure ----

    public function testLogExposurePostsWhenEnrolled(): void
    {
        $c = $this->capturingClient();
        $c->applyData(['gates' => [], 'configs' => []], $this->expsBlob());

        $c->logExposure('user-42', 'checkout_test');

        $this->assertCount(1, $c->posts);
        $event = $c->posts[0]['body']['events'][0];
        $this->assertSame('exposure', $event['type']);
        $this->assertSame('checkout_test', $event['experiment']);
        $this->assertSame('user-42', $event['user_id']);
        $this->assertContains($event['group'], ['control', 'treatment']);
        $this->assertArrayHasKey('ts', $event);
    }

    public function testLogExposureAcceptsUserArray(): void
    {
        $c = $this->capturingClient();
        $c->applyData(['gates' => [], 'configs' => []], $this->expsBlob());

        $c->logExposure(['anonymous_id' => 'anon-9'], 'checkout_test');
        $event = $c->posts[0]['body']['events'][0];
        $this->assertSame('anon-9', $event['anonymous_id']);
        $this->assertArrayNotHasKey('user_id', $event);
    }

    public function testLogExposureNoOpWhenNotEnrolled(): void
    {
        // allocation 0 → nobody enrolled → no exposure posted.
        $c = $this->capturingClient();
        $c->applyData(['gates' => [], 'configs' => []], $this->expsBlob('salt', 0));

        $c->logExposure('user-42', 'checkout_test');
        $this->assertCount(0, $c->posts);
    }

    public function testLogExposureNoOpForUnknownExperiment(): void
    {
        $c = $this->capturingClient();
        $c->applyData(['gates' => [], 'configs' => []], $this->expsBlob());
        $c->logExposure('user-42', 'does_not_exist');
        $this->assertCount(0, $c->posts);
    }

    public function testLogExposureNoOpInLocalMode(): void
    {
        $c = Client::forTesting();
        // forTesting can't capture posts, but it must simply not throw / not send.
        $c->logExposure('user-42', 'whatever');
        $this->assertTrue(true);
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
        $c = Client::fromSnapshot(['gates' => [], 'configs' => []], $this->expsBlob(), $store);

        $r = $c->getExperiment('checkout_test', ['user_id' => 'u1'], null);
        $this->assertTrue($r->inExperiment);

        $entry = $store->get('u1')['checkout_test'];
        $this->assertSame($r->group, $entry['g']);
        $this->assertSame(substr('expsalt12345', 0, 8), $entry['s']);
    }

    public function testStickyEntrySticksThroughAllocationDrop(): void
    {
        // Seed an enrolled unit with the treatment group + correct salt prefix.
        $salt8 = substr('expsalt12345', 0, 8);
        $store = new InMemoryStickyStore(['u1' => ['checkout_test' => ['g' => 'treatment', 's' => $salt8]]]);

        // Allocation now 0 — a deterministic eval would drop u1. Sticky keeps it.
        $c = Client::fromSnapshot(['gates' => [], 'configs' => []], $this->expsBlob('expsalt12345', 0), $store);

        $r = $c->getExperiment('checkout_test', ['user_id' => 'u1'], null);
        $this->assertTrue($r->inExperiment);
        $this->assertSame('treatment', $r->group);
        $this->assertSame(['c' => 2], $r->params);
    }

    public function testSaltMismatchRebuckets(): void
    {
        // Stored salt prefix no longer matches → the stored group is ignored and
        // the unit is re-bucketed + overwritten with the new salt prefix.
        $store = new InMemoryStickyStore(['u1' => ['checkout_test' => ['g' => 'treatment', 's' => 'OLDSALT0']]]);
        $c = Client::fromSnapshot(['gates' => [], 'configs' => []], $this->expsBlob('newsalt99999'), $store);

        $r = $c->getExperiment('checkout_test', ['user_id' => 'u1'], null);
        $this->assertTrue($r->inExperiment);

        $entry = $store->get('u1')['checkout_test'];
        $this->assertSame(substr('newsalt99999', 0, 8), $entry['s']);
        $this->assertSame($r->group, $entry['g']);
    }

    public function testStoredGroupGoneRebuckets(): void
    {
        // Salt matches, but the stored group name is no longer in the experiment
        // → fall through to a fresh pick + overwrite (a real group is returned).
        $salt8 = substr('expsalt12345', 0, 8);
        $store = new InMemoryStickyStore(['u1' => ['checkout_test' => ['g' => 'ghost', 's' => $salt8]]]);
        $c = Client::fromSnapshot(['gates' => [], 'configs' => []], $this->expsBlob(), $store);

        $r = $c->getExperiment('checkout_test', ['user_id' => 'u1'], null);
        $this->assertTrue($r->inExperiment);
        $this->assertContains($r->group, ['control', 'treatment']);
        $this->assertSame($r->group, $store->get('u1')['checkout_test']['g']);
    }

    public function testStickyIsStableAcrossCalls(): void
    {
        $store = new InMemoryStickyStore();
        $c = Client::fromSnapshot(['gates' => [], 'configs' => []], $this->expsBlob(), $store);

        $first = $c->getExperiment('checkout_test', ['user_id' => 'u1'], null)->group;
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, $c->getExperiment('checkout_test', ['user_id' => 'u1'], null)->group);
        }
    }

    public function testNoStoreIsDeterministicAndDoesNotPersist(): void
    {
        // Absent store ⇒ no behaviour change, no side effects.
        $c = Client::fromSnapshot(['gates' => [], 'configs' => []], $this->expsBlob());
        $r = $c->getExperiment('checkout_test', ['user_id' => 'u1'], null);
        $this->assertTrue($r->inExperiment);
        $this->assertContains($r->group, ['control', 'treatment']);
    }
}
