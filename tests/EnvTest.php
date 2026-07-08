<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Engine;
use Shipeasy\Env;

/**
 * Environment-derived network + telemetry (egress) defaults.
 *
 * The suite bootstrap sets SHIPEASY_ENV=production so the existing network tests
 * keep working; each test here saves and restores the native env vars it touches
 * so it can assert the dev/prod branching in isolation.
 */
final class EnvTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['SHIPEASY_ENV', 'APP_ENV', 'ENV'] as $k) {
            $this->savedEnv[$k] = getenv($k);
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            if ($v === false) {
                putenv($k);
                unset($_ENV[$k], $_SERVER[$k]);
            } else {
                putenv("$k=$v");
                $_ENV[$k] = $v;
                $_SERVER[$k] = $v;
            }
        }
        Engine::resetForTesting();
    }

    private function setNativeEnv(string $value): void
    {
        putenv("SHIPEASY_ENV=$value");
        $_ENV['SHIPEASY_ENV'] = $value;
        $_SERVER['SHIPEASY_ENV'] = $value;
    }

    // ---- Env::isProductionEnv precedence ----

    public function testNativeProductionWins(): void
    {
        $this->setNativeEnv('production');
        $this->assertTrue(Env::isProductionEnv());
        $this->assertTrue(Env::isProductionEnv('dev')); // native beats configured
    }

    public function testNativeProdAliasAndCaseInsensitive(): void
    {
        $this->setNativeEnv('PROD');
        $this->assertTrue(Env::isProductionEnv());
        $this->setNativeEnv('Production');
        $this->assertTrue(Env::isProductionEnv());
    }

    public function testNativeNonProductionValueIsNotProd(): void
    {
        $this->setNativeEnv('staging');
        $this->assertFalse(Env::isProductionEnv());
        $this->assertFalse(Env::isProductionEnv('prod')); // native "staging" beats configured "prod"
        $this->setNativeEnv('development');
        $this->assertFalse(Env::isProductionEnv());
    }

    public function testAppEnvFallbackWhenNoShipeasyEnv(): void
    {
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $this->assertTrue(Env::isProductionEnv());

        putenv('APP_ENV=local');
        $_ENV['APP_ENV'] = 'local';
        $this->assertFalse(Env::isProductionEnv());

        // SHIPEASY_ENV takes precedence over APP_ENV when both present.
        $this->setNativeEnv('prod');
        putenv('APP_ENV=local');
        $_ENV['APP_ENV'] = 'local';
        $this->assertTrue(Env::isProductionEnv());
    }

    public function testFallsBackToConfiguredEnvWhenNoNativeVar(): void
    {
        // No native env var set (setUp cleared them all).
        $this->assertTrue(Env::isProductionEnv());          // defaults to prod
        $this->assertTrue(Env::isProductionEnv('prod'));
        $this->assertFalse(Env::isProductionEnv('dev'));
        $this->assertFalse(Env::isProductionEnv('staging'));
    }

    // ---- Egress default: OFF in dev, ON in prod ----

    /**
     * Engine subclass that captures /collect posts and never touches the network.
     */
    private function capturingEngine(string $env, ?bool $isNetworkEnabled): object
    {
        return new class ('k', null, $env, null, null, [], null, null, false, $isNetworkEnabled) extends Engine {
            /** @var array<int, array{path: string, body: string}> */
            public array $posts = [];
            protected function postNonBlocking(string $path, string $body): void
            {
                $this->posts[] = ['path' => $path, 'body' => $body];
            }
        };
    }

    public function testOfflineByDefaultInDev(): void
    {
        // No native env var; configured env 'dev' ⇒ not production ⇒ offline.
        $c = $this->capturingEngine('dev', null);
        $c->track('u1', 'purchase', ['amount' => 42]);
        $c->see(new \RuntimeException('boom'))->causesThe('checkout')->to('use cached prices');
        $this->assertCount(0, $c->posts, 'dev engine must send nothing by default');
    }

    public function testExplicitNetworkOnOverridesDevDefault(): void
    {
        $c = $this->capturingEngine('dev', true); // force egress on despite dev
        $c->track('u1', 'purchase', ['amount' => 42]);
        $this->assertCount(1, $c->posts, 'explicit isNetworkEnabled=true must re-enable egress');
        $this->assertSame('/collect', $c->posts[0]['path']);
    }

    public function testOnByDefaultInProduction(): void
    {
        // Configured env 'prod' with no native var ⇒ production ⇒ egress on.
        $c = $this->capturingEngine('prod', null);
        $c->track('u1', 'purchase', ['amount' => 42]);
        $this->assertCount(1, $c->posts, 'prod engine sends by default');
    }

    public function testNativeEnvDrivesDefaultOverConfiguredProd(): void
    {
        // Native env says development, even though configured env is 'prod'.
        $this->setNativeEnv('development');
        $c = $this->capturingEngine('prod', null);
        $c->track('u1', 'purchase', ['amount' => 42]);
        $this->assertCount(0, $c->posts, 'native non-prod env forces offline default');
    }

    public function testExplicitNetworkOffOverridesProd(): void
    {
        $c = $this->capturingEngine('prod', false); // force offline in prod
        $c->track('u1', 'purchase', ['amount' => 42]);
        $this->assertCount(0, $c->posts, 'explicit isNetworkEnabled=false silences a prod engine');
    }

    public function testOfflineEngineReadsOverridesWithoutNetwork(): void
    {
        // Offline (dev default) engine still evaluates in-code overrides and is
        // "initialized" so reads return values rather than CLIENT_NOT_READY.
        $c = $this->capturingEngine('dev', null);
        $c->overrideFlag('new_checkout', true);
        $c->overrideConfig('copy', ['headline' => 'Hi']);
        $this->assertTrue($c->getFlag('new_checkout', ['user_id' => 'u1']));
        $this->assertSame(['headline' => 'Hi'], $c->getConfig('copy'));
        // A flag that is not overridden and not in a (empty) blob returns default.
        $this->assertFalse($c->getFlag('missing', ['user_id' => 'u1'], false));
        $this->assertCount(0, $c->posts);
    }
}
