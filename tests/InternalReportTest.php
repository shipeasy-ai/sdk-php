<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Engine;
use Shipeasy\InternalReport;

/**
 * Self-monitoring channel: when the SDK swallows an internal ("on our end")
 * error via a fail-safe read guard, it also ships a structured see event to
 * Shipeasy's OWN project — a baked-in destination + public client key, distinct
 * from the consumer's see() path. Mirrors the TS
 * src/__tests__/internal-report.test.ts contract: it pins the wire shape, the
 * enable gating, the dedup, and the no-throw guarantee, plus the fail-safe-read
 * integration (reports the swallowed error AND still returns the fallback).
 */
final class InternalReportTest extends TestCase
{
    /**
     * A real-looking client key to exercise the send path (the baked default is
     * an inert placeholder until the real key is minted).
     */
    private const FAKE_KEY = 'sdk_client_testfakekey00000000000000000000';

    /** @var array<int, array{url: string, key: string, body: array}> */
    private array $sends = [];

    protected function setUp(): void
    {
        $this->sends = [];
        InternalReport::resetForTest();
        InternalReport::setIngestKeyForTest(self::FAKE_KEY);
        InternalReport::setSenderForTest(function (string $url, string $key, string $body): void {
            $this->sends[] = ['url' => $url, 'key' => $key, 'body' => json_decode($body, true)];
        });
    }

    protected function tearDown(): void
    {
        InternalReport::resetForTest();
        Engine::resetForTesting();
    }

    /** Flatten captured sends into a list of events. */
    private function events(): array
    {
        $out = [];
        foreach ($this->sends as $s) {
            foreach ($s['body']['events'] as $e) {
                $out[] = $e;
            }
        }
        return $out;
    }

    // ---- destination + wire shape ----

    public function testPostsToBakedIngestWithPublicClientKey(): void
    {
        InternalReport::setContext('server', '9.9.9', true);
        InternalReport::report('getFlag', new \TypeError('cannot read foo'));

        $this->assertCount(1, $this->sends);
        $this->assertSame(InternalReport::INGEST_URL, $this->sends[0]['url']);
        $this->assertSame(self::FAKE_KEY, $this->sends[0]['key']);
    }

    public function testBuildsStableConsequenceSubjectOutcomeAndSdkMarker(): void
    {
        InternalReport::setContext('server', '9.9.9', true);
        InternalReport::report('getExperiment', new \RuntimeException('boom'));

        $ev = $this->events()[0];
        $this->assertSame('error', $ev['type']);
        $this->assertSame('caught', $ev['kind']);
        $this->assertSame('getExperiment', $ev['subject']);
        $this->assertSame('returned a safe default', $ev['outcome']);
        $this->assertSame('RuntimeException', $ev['error_type']);
        $this->assertSame('boom', $ev['message']);
        $this->assertSame('server', $ev['side']);
        $this->assertSame('9.9.9', $ev['sdk_version']);
        $this->assertSame('php', $ev['extras']['sdk']);
    }

    public function testDoesNotAttachConsumerEnv(): void
    {
        InternalReport::setContext('server', '9.9.9', true);
        InternalReport::report('getKillswitch', new \RuntimeException('x'));

        $ev = $this->events()[0];
        $this->assertArrayNotHasKey('env', $ev);
    }

    // ---- enable gating ----

    public function testNoOpBeforeContextIsSet(): void
    {
        // resetForTest() cleared the context; do not set one.
        InternalReport::report('getFlag', new \RuntimeException('boom'));
        $this->assertCount(0, $this->sends);
    }

    public function testNoOpWhenDisabled(): void
    {
        InternalReport::setContext('server', '9.9.9', false);
        InternalReport::report('getFlag', new \RuntimeException('boom'));
        $this->assertCount(0, $this->sends);
    }

    public function testInertWhileIngestKeyIsUnprovisionedPlaceholder(): void
    {
        InternalReport::setIngestKeyForTest(InternalReport::PLACEHOLDER_KEY);
        InternalReport::setContext('server', '9.9.9', true);
        InternalReport::report('getFlag', new \RuntimeException('boom'));
        $this->assertCount(0, $this->sends);
    }

    // ---- resilience ----

    public function testDedupesIdenticalInternalErrorsWithinWindow(): void
    {
        InternalReport::setContext('server', '9.9.9', true);
        // Same throwable => same top stack frame => one fingerprint.
        $err = new \RuntimeException('same');
        InternalReport::report('getFlag', $err);
        InternalReport::report('getFlag', $err);
        $this->assertCount(1, $this->sends);
    }

    public function testNeverThrowsEvenWhenSenderThrows(): void
    {
        InternalReport::setSenderForTest(function (): void {
            throw new \RuntimeException('network down');
        });
        InternalReport::setContext('server', '9.9.9', true);
        InternalReport::report('getFlag', new \RuntimeException('boom'));
        $this->assertTrue(true); // reached here without an exception escaping
    }

    // ---- fail-safe-read integration ----

    /**
     * A non-local Engine whose fail-safe reads run for real, but a getExperiment
     * with an injected throwing sticky store trips the internal guard. Assert the
     * guard reports AND still returns the not-enrolled fallback.
     */
    public function testGuardReportsSwallowedErrorAndStillReturnsFallback(): void
    {
        // A user-supplied store whose get() throws mid-read.
        $boom = new class implements \Shipeasy\StickyBucketStore {
            public function get(string $unit): ?array
            {
                throw new \RuntimeException('sticky store exploded');
            }
            public function set(string $unit, string $exp, array $entry): void
            {
            }
        };

        // Non-local engine (localMode off) so the internal channel is enabled;
        // telemetry off, no network for reads (seeded blob).
        $engine = new Engine('k', null, 'prod', true, null, [], $boom);
        // The constructor set the context enabled; re-point the sender + key
        // (setUp's sender was cleared? no — setUp ran first, but the Engine ctor
        // called setContext which reset side/version/enabled and rebuilt the
        // limiter only if null). Re-arm the send seam + key after construction.
        InternalReport::setIngestKeyForTest(self::FAKE_KEY);
        InternalReport::setSenderForTest(function (string $url, string $key, string $body): void {
            $this->sends[] = ['url' => $url, 'key' => $key, 'body' => json_decode($body, true)];
        });

        $engine->applyData(null, [
            'universes' => ['u1' => ['holdout_range' => null]],
            'experiments' => [
                'checkout_test' => [
                    'universe' => 'u1',
                    'status' => 'running',
                    'salt' => 'expsalt12345',
                    'allocationPct' => 10000,
                    'targetingGate' => null,
                    'groups' => [
                        ['name' => 'control', 'weight' => 5000, 'params' => ['c' => 1]],
                        ['name' => 'treatment', 'weight' => 5000, 'params' => ['c' => 2]],
                    ],
                ],
            ],
        ]);

        $default = ['c' => 99];
        $result = $engine->getExperiment('checkout_test', ['user_id' => 'u_1'], $default);

        // Still returned the safe not-enrolled default.
        $this->assertFalse($result->inExperiment);
        $this->assertSame('control', $result->group);
        $this->assertSame($default, $result->params);

        // And reported the swallowed internal error to the internal channel.
        $this->assertCount(1, $this->sends);
        $ev = $this->events()[0];
        $this->assertSame('getExperiment', $ev['subject']);
        $this->assertSame('returned a safe default', $ev['outcome']);
        $this->assertSame('sticky store exploded', $ev['message']);
    }

    public function testGuardDoesNotReportOnSuccessfulRead(): void
    {
        InternalReport::setContext('server', '9.9.9', true);
        $engine = new Engine('k', null, 'prod', true);
        // Re-arm after the Engine ctor reset the context/limiter.
        InternalReport::setIngestKeyForTest(self::FAKE_KEY);
        InternalReport::setSenderForTest(function (string $url, string $key, string $body): void {
            $this->sends[] = ['url' => $url, 'key' => $key, 'body' => json_decode($body, true)];
        });
        $engine->applyData(['gates' => []], null);

        $out = $engine->getFlag('missing', ['user_id' => 'u_1'], true);
        $this->assertTrue($out); // default returned for a not-found flag, no throw
        $this->assertCount(0, $this->sends);
    }

    public function testLocalTestModeKeepsChannelInert(): void
    {
        // forTesting() forces the internal channel off.
        Engine::forTesting();
        InternalReport::setIngestKeyForTest(self::FAKE_KEY);
        InternalReport::setSenderForTest(function (string $url, string $key, string $body): void {
            $this->sends[] = ['url' => $url, 'key' => $key, 'body' => json_decode($body, true)];
        });
        InternalReport::report('getFlag', new \RuntimeException('boom'));
        $this->assertCount(0, $this->sends);
    }
}
