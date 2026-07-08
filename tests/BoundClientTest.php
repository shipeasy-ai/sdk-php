<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Client;
use Shipeasy\Engine;

use function Shipeasy\configure;

/**
 * The configure() + user-bound Client front door (0.8.0). Each test resets the
 * package-global engine + attributes transform so order is irrelevant.
 */
final class BoundClientTest extends TestCase
{
    protected function setUp(): void
    {
        Engine::resetForTesting();
    }

    protected function tearDown(): void
    {
        Engine::resetForTesting();
    }

    /** A no-network engine with one fully-on gate and a config; identity attrs. */
    private function snapshotEngine(): Engine
    {
        return Engine::fromSnapshot(
            [
                'gates' => [
                    'fully_on' => ['enabled' => true, 'rules' => [], 'rolloutPct' => 10000, 'salt' => 's1'],
                    // enabled, only matches plan == enterprise
                    'enterprise_only' => [
                        'enabled' => true,
                        'rules' => [['attr' => 'plan', 'op' => 'eq', 'value' => 'enterprise']],
                        'rolloutPct' => 10000,
                        'salt' => 's2',
                    ],
                ],
                'configs' => [
                    'present_cfg' => ['value' => ['headline' => 'Hi']],
                ],
                'killswitches' => [
                    'panic' => ['killed' => true, 'switches' => ['beta' => false]],
                ],
            ],
            ['experiments' => [], 'universes' => []],
        );
    }

    /** configure() then new Client(...)->getFlag(...) resolves real rules. */
    public function testConfigureThenBoundClientGetFlag(): void
    {
        // Register a snapshot engine as the global, then configure() (first-wins:
        // reuses the existing engine, so no network is attempted).
        Engine::setDefault($this->snapshotEngine());
        configure('server-key');

        $client = new Client(['user_id' => 'u1']);

        $this->assertTrue($client->getFlag('fully_on'));
        $this->assertFalse($client->getFlag('does_not_exist'));
        $this->assertTrue($client->getFlag('does_not_exist', true)); // default arg
        $this->assertSame(['headline' => 'Hi'], $client->getConfig('present_cfg'));
        $this->assertSame('fallback', $client->getConfig('missing_cfg', 'fallback'));
        $this->assertTrue($client->getKillswitch('panic'));
        $this->assertFalse($client->getKillswitch('panic', 'beta')); // named override
    }

    /** The attributes transform maps a raw user object to the attribute map. */
    public function testAttributesTransformApplied(): void
    {
        Engine::setDefault($this->snapshotEngine());

        // Transform reads a custom object shape into Shipeasy targeting attrs.
        configure('server-key', static fn ($u) => [
            'user_id' => $u->id,
            'plan' => $u->tier,
        ]);

        $enterprise = new Client((object) ['id' => 'u-ent', 'tier' => 'enterprise']);
        $free = new Client((object) ['id' => 'u-free', 'tier' => 'free']);

        // The enterprise_only gate only matches plan == enterprise, proving the
        // transform's mapped `plan` attribute reached evaluation.
        $this->assertTrue($enterprise->getFlag('enterprise_only'));
        $this->assertFalse($free->getFlag('enterprise_only'));
    }

    /** Default (no transform): the user array IS the attribute map. */
    public function testIdentityTransformDefault(): void
    {
        Engine::setDefault($this->snapshotEngine());
        configure('server-key');

        $client = new Client(['user_id' => 'u1', 'plan' => 'enterprise']);
        $this->assertTrue($client->getFlag('enterprise_only'));
    }

    /** Constructing a Client before configure() fails loudly. */
    public function testClientBeforeConfigureThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('configure');
        new Client(['user_id' => 'u1']);
    }

    /** configure() is first-config-wins (idempotent). */
    public function testConfigureFirstWins(): void
    {
        $first = $this->snapshotEngine();
        Engine::setDefault($first);
        $second = configure('another-key');
        $this->assertSame($first, $second);
        $this->assertSame($first, Engine::getDefault());
    }

    /**
     * A non-local engine that captures /collect POSTs instead of sending them,
     * seeded with one running 100%-allocation experiment. Registered as the
     * global default so `new Client(...)` binds to it.
     */
    private function capturingEngine(): object
    {
        $engine = new class ('k', null, 'prod', true) extends Engine {
            /** @var array<int, array{path: string, body: array}> */
            public array $posts = [];
            protected function postNonBlocking(string $path, string $body): void
            {
                $this->posts[] = ['path' => $path, 'body' => json_decode($body, true)];
            }
        };
        $engine->applyData(
            ['gates' => [], 'configs' => []],
            [
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
            ],
        );
        return $engine;
    }

    /** The bound Client.track derives the user id from user_id and reaches the engine. */
    public function testBoundClientTrackUsesUserId(): void
    {
        $engine = $this->capturingEngine();
        Engine::setDefault($engine);
        configure('server-key');

        (new Client(['user_id' => 'u1', 'plan' => 'pro']))
            ->track('purchase', ['amount' => 42]);

        $this->assertCount(1, $engine->posts);
        $this->assertSame('/collect', $engine->posts[0]['path']);
        $event = $engine->posts[0]['body']['events'][0];
        $this->assertSame('metric', $event['type']);
        $this->assertSame('purchase', $event['event_name']);
        $this->assertSame('u1', $event['user_id']);
        $this->assertSame(['amount' => 42], $event['properties']);
    }

    /** When no user_id is bound, track falls back to anonymous_id. */
    public function testBoundClientTrackFallsBackToAnonymousId(): void
    {
        $engine = $this->capturingEngine();
        Engine::setDefault($engine);
        configure('server-key');

        (new Client(['anonymous_id' => 'anon-9']))->track('ping');

        $event = $engine->posts[0]['body']['events'][0];
        $this->assertSame('anon-9', $event['user_id']);
    }

    /**
     * The bound Client.universe()->assign() forwards the bound attribute map to
     * the engine and auto-logs a single exposure for the enrolled unit.
     */
    public function testBoundClientUniverseAssignAutoLogsExposure(): void
    {
        $engine = $this->capturingEngine();
        Engine::setDefault($engine);
        configure('server-key');

        $a = (new Client(['user_id' => 'user-42']))->universe('u1')->assign();

        $this->assertTrue($a->enrolled());
        $this->assertSame('checkout_test', $a->name);
        $this->assertContains($a->group, ['control', 'treatment']);

        $this->assertCount(1, $engine->posts);
        $event = $engine->posts[0]['body']['events'][0];
        $this->assertSame('exposure', $event['type']);
        $this->assertSame('checkout_test', $event['experiment']);
        $this->assertSame('user-42', $event['user_id']);
        $this->assertContains($event['group'], ['control', 'treatment']);
    }
}
