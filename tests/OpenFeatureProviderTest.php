<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Client;
use Shipeasy\OpenFeature\ShipeasyProvider;

/**
 * Exercises the OpenFeature provider against the REAL open-feature/sdk
 * contract. When that package is not installed (no network), every test is
 * skipped so the suite stays green and the gap is reported as UNVERIFIED.
 *
 * Flags/configs are seeded through the SDK's own offline test facility
 * (Client::fromSnapshot) — no network, no API key.
 */
final class OpenFeatureProviderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(\OpenFeature\interfaces\provider\Provider::class)) {
            $this->markTestSkipped('open-feature/sdk not installed — provider is UNVERIFIED.');
        }
    }

    /**
     * Build an offline client seeded with gates + configs.
     *
     * Gates:
     *   on_for_all  — enabled, rolloutPct 10000 → evaluates true  (RULE_MATCH)
     *   off_for_all — enabled, rolloutPct 0     → evaluates false (DEFAULT)
     * Configs:
     *   greeting (string), max_items (int), ratio (float), theme (object)
     */
    private function seededClient(): Client
    {
        $flags = [
            'gates' => [
                'on_for_all' => ['enabled' => true, 'rolloutPct' => 10000, 'salt' => 's', 'rules' => []],
                'off_for_all' => ['enabled' => true, 'rolloutPct' => 0, 'salt' => 's', 'rules' => []],
            ],
            'configs' => [
                'greeting' => ['value' => 'hello'],
                'max_items' => ['value' => 42],
                'ratio' => ['value' => 1.5],
                'theme' => ['value' => ['color' => 'green', 'dark' => true]],
                // A string stored where an integer is requested → TYPE_MISMATCH.
                'not_a_number' => ['value' => 'oops'],
            ],
        ];

        return Client::fromSnapshot($flags, []);
    }

    private function ctx(string $targetingKey): \OpenFeature\interfaces\flags\EvaluationContext
    {
        return new \OpenFeature\implementation\flags\EvaluationContext($targetingKey);
    }

    public function testMetadataName(): void
    {
        $p = new ShipeasyProvider($this->seededClient());
        $this->assertSame('shipeasy', $p->getMetadata()->getName());
    }

    // ---- boolean / reason mapping ------------------------------------------

    public function testBooleanTargetingMatch(): void
    {
        $p = new ShipeasyProvider($this->seededClient());
        $r = $p->resolveBooleanValue('on_for_all', false, $this->ctx('u1'));

        $this->assertTrue($r->getValue());
        $this->assertSame('TARGETING_MATCH', $r->getReason());
        $this->assertNull($r->getError());
    }

    public function testBooleanDefaultReason(): void
    {
        $p = new ShipeasyProvider($this->seededClient());
        $r = $p->resolveBooleanValue('off_for_all', true, $this->ctx('u1'));

        // Gate evaluated false (no rollout) — that's the resolved value, not an
        // error fall-through to the OF default.
        $this->assertFalse($r->getValue());
        $this->assertSame('DEFAULT', $r->getReason());
        $this->assertNull($r->getError());
    }

    public function testBooleanFlagNotFound(): void
    {
        $p = new ShipeasyProvider($this->seededClient());
        $r = $p->resolveBooleanValue('missing', true, $this->ctx('u1'));

        $this->assertTrue($r->getValue()); // returns the default
        $this->assertSame('ERROR', $r->getReason());
        $this->assertNotNull($r->getError());
        $this->assertSame('FLAG_NOT_FOUND', (string) $r->getError()->getResolutionErrorCode());
    }

    public function testBooleanClientNotReady(): void
    {
        // forTesting() with no seed + a flag that IS seeded would be RULE_MATCH,
        // but an UNINITIALIZED client reports CLIENT_NOT_READY → PROVIDER_NOT_READY.
        $client = new Client('k', null, 'prod', true); // not initialized, no override
        $p = new ShipeasyProvider($client);
        $r = $p->resolveBooleanValue('whatever', false, $this->ctx('u1'));

        $this->assertFalse($r->getValue());
        $this->assertSame('ERROR', $r->getReason());
        $this->assertSame('PROVIDER_NOT_READY', (string) $r->getError()->getResolutionErrorCode());
    }

    public function testBooleanOverrideIsStatic(): void
    {
        $client = $this->seededClient();
        $client->overrideFlag('on_for_all', false);
        $p = new ShipeasyProvider($client);
        $r = $p->resolveBooleanValue('on_for_all', true, $this->ctx('u1'));

        $this->assertFalse($r->getValue());
        $this->assertSame('STATIC', $r->getReason());
        $this->assertNull($r->getError());
    }

    // ---- string / int / float / object ------------------------------------

    public function testStringResolves(): void
    {
        $p = new ShipeasyProvider($this->seededClient());
        $r = $p->resolveStringValue('greeting', 'fallback', $this->ctx('u1'));

        $this->assertSame('hello', $r->getValue());
        $this->assertSame('TARGETING_MATCH', $r->getReason());
    }

    public function testIntegerResolves(): void
    {
        $p = new ShipeasyProvider($this->seededClient());
        $r = $p->resolveIntegerValue('max_items', 0, $this->ctx('u1'));

        $this->assertSame(42, $r->getValue());
        $this->assertSame('TARGETING_MATCH', $r->getReason());
    }

    public function testFloatResolves(): void
    {
        $p = new ShipeasyProvider($this->seededClient());
        $r = $p->resolveFloatValue('ratio', 0.0, $this->ctx('u1'));

        $this->assertSame(1.5, $r->getValue());
        $this->assertSame('TARGETING_MATCH', $r->getReason());
    }

    public function testObjectResolves(): void
    {
        $p = new ShipeasyProvider($this->seededClient());
        $r = $p->resolveObjectValue('theme', [], $this->ctx('u1'));

        $this->assertSame(['color' => 'green', 'dark' => true], $r->getValue());
        $this->assertSame('TARGETING_MATCH', $r->getReason());
    }

    public function testConfigAbsentReturnsDefault(): void
    {
        $p = new ShipeasyProvider($this->seededClient());
        $r = $p->resolveStringValue('no_such_config', 'fallback', $this->ctx('u1'));

        $this->assertSame('fallback', $r->getValue());
        $this->assertSame('DEFAULT', $r->getReason());
        $this->assertNull($r->getError());
    }

    public function testConfigTypeMismatch(): void
    {
        $p = new ShipeasyProvider($this->seededClient());
        // 'not_a_number' holds a string but an integer is requested.
        $r = $p->resolveIntegerValue('not_a_number', 7, $this->ctx('u1'));

        $this->assertSame(7, $r->getValue()); // returns the default
        $this->assertSame('ERROR', $r->getReason());
        $this->assertSame('TYPE_MISMATCH', (string) $r->getError()->getResolutionErrorCode());
    }
}
