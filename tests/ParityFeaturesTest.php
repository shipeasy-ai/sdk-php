<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Engine;
use Shipeasy\FlagDetail;

final class ParityFeaturesTest extends TestCase
{
    /** A snapshot client with one enabled (100%), one disabled, one config. */
    private function snapshotClient(): Engine
    {
        return Engine::fromSnapshot(
            [
                'gates' => [
                    'fully_on' => ['enabled' => true, 'rules' => [], 'rolloutPct' => 10000, 'salt' => 's1'],
                    'turned_off' => ['enabled' => false, 'rules' => [], 'rolloutPct' => 10000, 'salt' => 's2'],
                    // enabled, but a never-matching rule → evalGate false → DEFAULT
                    'rule_denies' => [
                        'enabled' => true,
                        'rules' => [['attr' => 'plan', 'op' => 'eq', 'value' => 'enterprise']],
                        'rolloutPct' => 10000,
                        'salt' => 's3',
                    ],
                ],
                'configs' => [
                    'present_cfg' => ['value' => ['headline' => 'Hi']],
                ],
            ],
            ['experiments' => [], 'universes' => []],
        );
    }

    // ---- FEATURE A: defaults on getFlag / getConfig ----

    public function testGetFlagDefaultOnlyWhenNotFound(): void
    {
        $c = $this->snapshotClient();

        // Present + evaluates true → real value, default ignored.
        $this->assertTrue($c->getFlag('fully_on', ['user_id' => 'u1'], true));
        $this->assertTrue($c->getFlag('fully_on', ['user_id' => 'u1'], false));

        // Present but evaluates false (rule denies) → false, NOT the default.
        $this->assertFalse($c->getFlag('rule_denies', ['user_id' => 'u1'], true));

        // Disabled gate → false, NOT the default.
        $this->assertFalse($c->getFlag('turned_off', ['user_id' => 'u1'], true));

        // Missing gate → default is returned.
        $this->assertTrue($c->getFlag('does_not_exist', ['user_id' => 'u1'], true));
        $this->assertFalse($c->getFlag('does_not_exist', ['user_id' => 'u1'], false));
    }

    public function testGetFlagDefaultOnNotReady(): void
    {
        // A normal client that has never initialized → CLIENT_NOT_READY → default.
        $c = new Engine('k', null, 'prod', true);
        $this->assertTrue($c->getFlag('any', ['user_id' => 'u1'], true));
        $this->assertFalse($c->getFlag('any', ['user_id' => 'u1'], false));
        // Backwards-compatible default is false.
        $this->assertFalse($c->getFlag('any', ['user_id' => 'u1']));
    }

    public function testGetConfigDefaultWhenAbsent(): void
    {
        $c = $this->snapshotClient();
        $this->assertSame(['headline' => 'Hi'], $c->getConfig('present_cfg', 'fallback'));
        $this->assertSame('fallback', $c->getConfig('missing_cfg', 'fallback'));
        $this->assertNull($c->getConfig('missing_cfg')); // legacy: null default
    }

    public function testGetConfigOverrideStillWins(): void
    {
        $c = $this->snapshotClient();
        $c->overrideConfig('missing_cfg', 99);
        $this->assertSame(99, $c->getConfig('missing_cfg', 'fallback'));
    }

    // ---- FEATURE B: getFlagDetail reasons ----

    public function testReasonOverride(): void
    {
        $c = $this->snapshotClient();
        $c->overrideFlag('fully_on', false);
        $d = $c->getFlagDetail('fully_on', ['user_id' => 'u1']);
        $this->assertFalse($d->value);
        $this->assertSame(FlagDetail::OVERRIDE, $d->reason);
    }

    public function testReasonClientNotReady(): void
    {
        $c = new Engine('k', null, 'prod', true); // never initialized
        $d = $c->getFlagDetail('any', ['user_id' => 'u1']);
        $this->assertFalse($d->value);
        $this->assertSame(FlagDetail::CLIENT_NOT_READY, $d->reason);
    }

    public function testReasonFlagNotFound(): void
    {
        $d = $this->snapshotClient()->getFlagDetail('nope', ['user_id' => 'u1']);
        $this->assertFalse($d->value);
        $this->assertSame(FlagDetail::FLAG_NOT_FOUND, $d->reason);
    }

    public function testReasonOff(): void
    {
        $d = $this->snapshotClient()->getFlagDetail('turned_off', ['user_id' => 'u1']);
        $this->assertFalse($d->value);
        $this->assertSame(FlagDetail::OFF, $d->reason);
    }

    public function testReasonRuleMatch(): void
    {
        $d = $this->snapshotClient()->getFlagDetail('fully_on', ['user_id' => 'u1']);
        $this->assertTrue($d->value);
        $this->assertSame(FlagDetail::RULE_MATCH, $d->reason);
    }

    public function testReasonDefault(): void
    {
        $d = $this->snapshotClient()->getFlagDetail('rule_denies', ['user_id' => 'u1']);
        $this->assertFalse($d->value);
        $this->assertSame(FlagDetail::DEFAULT, $d->reason);
    }

    public function testGetFlagDelegatesToDetail(): void
    {
        $c = $this->snapshotClient();
        $this->assertTrue($c->getFlag('fully_on', ['user_id' => 'u1']));
        $this->assertFalse($c->getFlag('rule_denies', ['user_id' => 'u1']));
    }

    // ---- FEATURE C: change listeners ----

    public function testOnChangeFiresOnApply(): void
    {
        $c = Engine::forTesting();
        $hits = 0;
        $c->onChange(function () use (&$hits): void { $hits++; });

        // Drive a "refresh applied new data" through the internal seam.
        $c->applyData(['gates' => ['g' => ['enabled' => true, 'rules' => [], 'rolloutPct' => 10000, 'salt' => 's']]], null);
        $this->assertSame(1, $hits);

        $c->applyData(null, ['experiments' => [], 'universes' => []]);
        $this->assertSame(2, $hits);
    }

    public function testOnChangeUnsubscribe(): void
    {
        $c = Engine::forTesting();
        $hits = 0;
        $unsub = $c->onChange(function () use (&$hits): void { $hits++; });

        $c->applyData(['gates' => []], null);
        $this->assertSame(1, $hits);

        $unsub();
        $c->applyData(['gates' => []], null);
        $this->assertSame(1, $hits); // no further firing
    }

    public function testListenerExceptionDoesNotBreakOthers(): void
    {
        $c = Engine::forTesting();
        $hits = 0;
        $c->onChange(function (): void { throw new \RuntimeException('boom'); });
        $c->onChange(function () use (&$hits): void { $hits++; });

        $c->applyData(['gates' => []], null); // must not throw
        $this->assertSame(1, $hits);
    }

    public function testRefreshIsNoOpInLocalMode(): void
    {
        $c = Engine::forTesting();
        $hits = 0;
        $c->onChange(function () use (&$hits): void { $hits++; });
        $c->refresh(); // localMode → no fetch, no fire, no network
        $this->assertSame(0, $hits);
    }

    // ---- FEATURE D: offline snapshot ----

    public function testFromSnapshotEvaluatesWithNoNetwork(): void
    {
        $c = $this->snapshotClient();
        $c->init();     // no-op
        $c->initOnce(); // no-op

        $this->assertTrue($c->getFlag('fully_on', ['user_id' => 'u1']));
        $this->assertFalse($c->getFlag('turned_off', ['user_id' => 'u1']));
        $this->assertSame(['headline' => 'Hi'], $c->getConfig('present_cfg'));

        // Overrides apply on top of the snapshot.
        $c->overrideFlag('turned_off', true);
        $this->assertTrue($c->getFlag('turned_off', ['user_id' => 'u1']));
    }

    public function testFromFileReadsSnapshot(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'se_snap_') . '.json';
        file_put_contents($path, json_encode([
            'flags' => [
                'gates' => [
                    'beta' => ['enabled' => true, 'rules' => [], 'rolloutPct' => 10000, 'salt' => 'x'],
                ],
                'configs' => ['copy' => ['value' => 'hello']],
            ],
            'experiments' => ['experiments' => [], 'universes' => []],
        ]));

        try {
            $c = Engine::fromFile($path);
            $this->assertTrue($c->getFlag('beta', ['user_id' => 'u1']));
            $this->assertSame('hello', $c->getConfig('copy'));
        } finally {
            @unlink($path);
        }
    }

    public function testFromFileThrowsOnMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        Engine::fromFile('/no/such/file/at/all.json');
    }
}
