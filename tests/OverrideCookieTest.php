<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Client;
use Shipeasy\Engine;
use Shipeasy\FlagDetail;
use Shipeasy\OverrideCookie;
use Shipeasy\RequestOverrides;

use function Shipeasy\withRequestOverrides;

/** Signed DevTools override cookie (`se_ov`): cross-impl fixture + request scope (shipeasy #714). */
final class OverrideCookieTest extends TestCase
{
    private const KEY = 'AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA';
    private const KEY_ID = 'k1';
    private const COOKIE = 'eyJ2IjoxLCJwaWQiOiJwcm9qX2ZpeHR1cmUiLCJrZXlJZCI6ImsxIiwibm9uY2UiOiJmaXh0dXJlLW5vbmNlIiwiZXhwIjo0MTAyNDQ0ODAwLCJvdiI6eyJnYXRlcyI6eyJsb2JieV9ob2xkIjp0cnVlLCJvbGRfbmF2IjpmYWxzZX0sImNvbmZpZ3MiOnsiYmFubmVyIjp7InRleHQiOiJoaSJ9fSwiZXhwcyI6eyJjaGVja291dF9leHAiOiJ0cmVhdG1lbnQifX19.8qd-pHu1e96Th-jcu4BZOhLmZjwhKrgn-uI8IWBr5Og';
    private const EXPIRED = 'eyJ2IjoxLCJwaWQiOiJwcm9qX2ZpeHR1cmUiLCJrZXlJZCI6ImsxIiwibm9uY2UiOiJmaXh0dXJlLW5vbmNlIiwiZXhwIjoxMDAwLCJvdiI6eyJnYXRlcyI6eyJsb2JieV9ob2xkIjp0cnVlLCJvbGRfbmF2IjpmYWxzZX0sImNvbmZpZ3MiOnsiYmFubmVyIjp7InRleHQiOiJoaSJ9fSwiZXhwcyI6eyJjaGVja291dF9leHAiOiJ0cmVhdG1lbnQifX19.fZEp7eGjZSdi5EvTB-5NZLbZpVXbcWbIaEYRmPvPbd8';
    private const TAMPERED = 'eyJ2IjoxLCJwaWQiOiJwcm9qX2ZpeHR1cmUiLCJrZXlJZCI6ImsxIiwibm9uY2UiOiJmaXh0dXJlLW5vbmNlIiwiZXhwIjo0MTAyNDQ0ODAwLCJvdiI6eyJnYXRlcyI6eyJsb2JieV9ob2xkIjp0cnVlLCJvbGRfbmF2IjpmYWxzZX0sImNvbmZpZ3MiOnsiYmFubmVyIjp7InRleHQiOiJoaSJ9fSwiZXhwcyI6eyJjaGVja291dF9leHAiOiJ0cmVhdG1lbnQifX19.8qd-pHu1e96Th-jcu4BZOhLmZjwhKrgn-uI8IWBr5AA';

    protected function setUp(): void
    {
        Engine::resetForTesting();
        RequestOverrides::reset();
        unset($_COOKIE['se_ov']);
    }

    protected function tearDown(): void
    {
        Engine::resetForTesting();
        RequestOverrides::reset();
        unset($_COOKIE['se_ov']);
    }

    /** @param array<string,mixed> $extraFlags */
    private function engine(bool $withKey = true, array $extraFlags = []): Engine
    {
        $flags = [
            'gates' => [
                'lobby_hold' => ['enabled' => true, 'rules' => [], 'rolloutPct' => 0, 'salt' => 's'],
                'old_nav' => ['enabled' => true, 'rules' => [], 'rolloutPct' => 10000, 'salt' => 's'],
            ],
            'configs' => ['banner' => ['value' => ['text' => 'real']]],
            'killswitches' => ['panic' => ['killed' => false, 'switches' => ['beta' => false]]],
        ] + $extraFlags;
        if ($withKey) {
            $flags['overrideKey'] = self::KEY;
            $flags['overrideKeyId'] = self::KEY_ID;
        }
        $exps = [
            'universes' => ['u' => ['param_schema' => ['color' => ['default' => 'blue']]]],
            'experiments' => [],
        ];
        $e = Engine::fromSnapshot($flags, $exps);
        Engine::setDefault($e);
        return $e;
    }

    public function testFixtureVerifies(): void
    {
        $ov = OverrideCookie::verify(self::COOKIE, self::KEY, self::KEY_ID);
        $this->assertNotNull($ov);
        $this->assertSame(['lobby_hold' => true, 'old_nav' => false], $ov['gates']);
        $this->assertSame(['banner' => ['text' => 'hi']], $ov['configs']);
        $this->assertSame(['checkout_exp' => 'treatment'], $ov['exps']);
    }

    public function testRejections(): void
    {
        $this->assertNull(OverrideCookie::verify(self::EXPIRED, self::KEY, self::KEY_ID));
        $this->assertNull(OverrideCookie::verify(self::TAMPERED, self::KEY, self::KEY_ID));
        $this->assertNull(OverrideCookie::verify(self::COOKIE, self::KEY, 'k2'));
        $this->assertNull(OverrideCookie::verify(self::COOKIE, 'AAAA', self::KEY_ID));
        $this->assertNull(OverrideCookie::verify('nodot', self::KEY, self::KEY_ID));
        $this->assertNull(OverrideCookie::verify('a.b.c', self::KEY, self::KEY_ID));
        $this->assertNull(OverrideCookie::verify('.sig', self::KEY, self::KEY_ID));
    }

    public function testExtract(): void
    {
        $this->assertSame(self::COOKIE, OverrideCookie::extract(self::COOKIE));
        $this->assertSame(self::COOKIE, OverrideCookie::extract('a=1; se_ov=' . self::COOKIE . '; b=2'));
        $this->assertSame('x.y', OverrideCookie::extract('se_ov=' . rawurlencode('x.y')));
        $this->assertNull(OverrideCookie::extract('a=1; b=2'));
        $this->assertNull(OverrideCookie::extract(null));
        $this->assertNull(OverrideCookie::extract(''));
    }

    public function testScopeSetsAndClears(): void
    {
        $this->engine();
        $c = new Client(['user_id' => 'u1']);
        $this->assertFalse($c->getFlag('lobby_hold'));
        $inside = withRequestOverrides('a=1; se_ov=' . self::COOKIE, fn () => $c->getFlagDetail('lobby_hold'));
        $this->assertTrue($inside->value);
        $this->assertSame(FlagDetail::OVERRIDE, $inside->reason);
        $this->assertFalse($c->getFlag('lobby_hold'));
        $this->assertSame(FlagDetail::RULE_MATCH, $c->getFlagDetail('old_nav')->reason);
    }

    public function testScopeClearsOnException(): void
    {
        $this->engine();
        $c = new Client(['user_id' => 'u1']);
        try {
            withRequestOverrides(self::COOKIE, function () { throw new \RuntimeException('x'); });
        } catch (\RuntimeException $e) {
        }
        $this->assertFalse($c->getFlag('lobby_hold'));
    }

    public function testGateFalseOverridesOnGate(): void
    {
        $this->engine();
        $c = new Client(['user_id' => 'u1']);
        $this->assertFalse(withRequestOverrides(self::COOKIE, fn () => $c->getFlag('old_nav')));
        $this->assertTrue($c->getFlag('old_nav'));
    }

    public function testKillswitchAndConfigOverride(): void
    {
        $this->engine(true, ['gates' => [
            'panic' => ['enabled' => true, 'rules' => [], 'rolloutPct' => 0, 'salt' => 's'],
        ]]);
        $c = new Client(['user_id' => 'u1']);
        $cookie = $this->signed(['gates' => ['panic' => true], 'configs' => ['banner' => ['text' => 'hi']]]);
        $this->assertTrue(withRequestOverrides($cookie, fn () => $c->getKillswitch('panic')));
        // per-switch-key reads are left alone
        $this->assertFalse(withRequestOverrides($cookie, fn () => $c->getKillswitch('panic', 'beta')));
        $this->assertFalse($c->getKillswitch('panic'));
        $this->assertSame(['text' => 'hi'], withRequestOverrides($cookie, fn () => $c->getConfig('banner')));
        $this->assertSame(['text' => 'real'], $c->getConfig('banner'));
    }

    public function testFixtureConfigOverride(): void
    {
        $this->engine();
        $c = new Client(['user_id' => 'u1']);
        $this->assertSame(['text' => 'hi'], withRequestOverrides(self::COOKIE, fn () => $c->getConfig('banner')));
        $this->assertTrue(withRequestOverrides(self::COOKIE, fn () => $c->getFlag('lobby_hold')));
    }

    public function testProgrammaticOverrideWins(): void
    {
        $e = $this->engine();
        $e->overrideFlag('lobby_hold', false);
        $e->overrideConfig('banner', 'prog');
        $c = new Client(['user_id' => 'u1']);
        $this->assertFalse(withRequestOverrides(self::COOKIE, fn () => $c->getFlag('lobby_hold')));
        $this->assertSame('prog', withRequestOverrides(self::COOKIE, fn () => $c->getConfig('banner')));
    }

    public function testFailsClosedWithoutKeyOrBadCookie(): void
    {
        $this->engine(false);
        $c = new Client(['user_id' => 'u1']);
        $this->assertFalse(withRequestOverrides(self::COOKIE, fn () => $c->getFlag('lobby_hold')));
        Engine::resetForTesting();
        $this->engine();
        $c = new Client(['user_id' => 'u1']);
        foreach ([self::EXPIRED, self::TAMPERED, 'garbage', null] as $bad) {
            $this->assertFalse(withRequestOverrides($bad, fn () => $c->getFlag('lobby_hold')));
        }
    }

    public function testAutoPickupFromCookieSuperglobal(): void
    {
        $this->engine();
        $c = new Client(['user_id' => 'u1']);
        $this->assertFalse($c->getFlag('lobby_hold'));
        $_COOKIE['se_ov'] = self::COOKIE;
        $this->assertTrue($c->getFlag('lobby_hold'));
        // an explicit null scope suppresses the auto-pickup
        $this->assertFalse(withRequestOverrides(null, fn () => $c->getFlag('lobby_hold')));
        $_COOKIE['se_ov'] = self::TAMPERED;
        $this->assertFalse($c->getFlag('lobby_hold'));
    }

    public function testExperimentGroupForced(): void
    {
        $flags = ['gates' => [], 'overrideKey' => self::KEY, 'overrideKeyId' => self::KEY_ID];
        $exps = [
            'universes' => ['u' => ['param_schema' => ['color' => ['default' => 'blue']]]],
            'experiments' => ['checkout_exp' => [
                'universe' => 'u', 'status' => 'running', 'salt' => 's', 'allocationPct' => 0,
                'groups' => [
                    ['name' => 'control', 'weight' => 5000, 'params' => []],
                    ['name' => 'treatment', 'weight' => 5000, 'params' => ['color' => 'red']],
                ],
            ]],
        ];
        Engine::setDefault(Engine::fromSnapshot($flags, $exps));
        $c = new Client(['user_id' => 'u1']);
        $a = withRequestOverrides(self::COOKIE, fn () => $c->universe('u')->assign());
        $this->assertSame('treatment', $a->group);
        $this->assertSame('red', $a->get('color', 'x'));
        $this->assertNull($c->universe('u')->assign()->group);
    }

    /** Sign a payload with the fixture key (mirrors the DevTools overlay). */
    private function signed(array $ov): string
    {
        $nonce = 'n1';
        $exp = 4102444800;
        $payload = ['v' => 1, 'pid' => 'p', 'keyId' => self::KEY_ID, 'nonce' => $nonce, 'exp' => $exp, 'ov' => $ov];
        $kp = OverrideCookie::b64uDecode(self::KEY);
        $ks = hash_hmac('sha256', "se-ov-sess:v1:$nonce:$exp", $kp, true);
        $body = OverrideCookie::b64uEncode(json_encode($payload));
        return $body . '.' . OverrideCookie::b64uEncode(hash_hmac('sha256', $body, $ks, true));
    }
}
