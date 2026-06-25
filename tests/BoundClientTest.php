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
}
