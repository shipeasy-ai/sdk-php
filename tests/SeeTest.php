<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Engine;
use Shipeasy\ControlFlowChain;
use Shipeasy\ExpectedRegistry;
use Shipeasy\See;

use function Shipeasy\see;
use function Shipeasy\seeViolation;
use function Shipeasy\controlFlowException;

/**
 * Coverage for see() — structured error reporting (the cross-SDK errors
 * primitive). Mirrors the Python tests/test_see.py. The real path is exercised
 * by subclassing Engine and capturing /collect POST bodies, the same pattern as
 * StickyAndExposureTest.
 */
final class SeeTest extends TestCase
{
    /** A non-local client that captures /collect POSTs instead of sending them. */
    private function capturingClient(array $privateAttributes = []): object
    {
        return new class ('k', null, 'prod', true, null, $privateAttributes) extends Engine {
            /** @var array<int, array{path: string, body: array}> */
            public array $posts = [];
            protected function postNonBlocking(string $path, string $body): void
            {
                $this->posts[] = ['path' => $path, 'body' => json_decode($body, true)];
            }
        };
    }

    /** Flatten the captured posts into a list of events. */
    private function events(object $c): array
    {
        $out = [];
        foreach ($c->posts as $p) {
            foreach ($p['body']['events'] as $e) {
                $out[] = $e;
            }
        }
        return $out;
    }

    public function testCaughtThrowableReportsErrorEvent(): void
    {
        $c = $this->capturingClient();
        try {
            throw new \RuntimeException('boom');
        } catch (\RuntimeException $e) {
            $c->see($e)->causesThe('checkout')->to('use cached prices');
        }
        $ev = $this->events($c)[0];
        $this->assertSame('error', $ev['type']);
        $this->assertSame('caught', $ev['kind']);
        $this->assertSame('RuntimeException', $ev['error_type']);
        $this->assertSame('boom', $ev['message']);
        $this->assertSame('checkout', $ev['subject']);
        $this->assertSame('use cached prices', $ev['outcome']);
        $this->assertSame('server', $ev['side']);
        $this->assertSame(Engine::VERSION, $ev['sdk_version']);
        $this->assertSame('prod', $ev['env']);
        $this->assertArrayHasKey('stack', $ev);
        $this->assertArrayHasKey('ts', $ev);
    }

    public function testViolationUsesViolationKindAndNoStack(): void
    {
        $c = $this->capturingClient();
        $c->seeViolation('large query')->causesThe('search results')->to('be trimmed');
        $ev = $this->events($c)[0];
        $this->assertSame('violation', $ev['kind']);
        $this->assertSame('large query', $ev['error_type']);
        $this->assertSame('large query', $ev['message']);
        $this->assertSame('search results', $ev['subject']);
        $this->assertArrayNotHasKey('stack', $ev);
    }

    public function testExtrasBeforeToAreSanitizedAndSent(): void
    {
        $c = $this->capturingClient();
        $c->see(new \RuntimeException('x'))
            ->causesThe('photo upload')
            ->extras(['photo_id' => 'p1', 'size' => 42, 'ok' => true, 'skip' => null])
            ->to('be rejected');
        $ev = $this->events($c)[0];
        $this->assertSame(['photo_id' => 'p1', 'size' => 42, 'ok' => true], $ev['extras']);
    }

    public function testSanitizeExtrasCapsKeysAndValueLength(): void
    {
        $big = [];
        for ($i = 0; $i < 30; $i++) {
            $big["k$i"] = $i;
        }
        $big['long'] = str_repeat('x', 500);
        $out = See::sanitizeExtras($big);
        $this->assertLessThanOrEqual(20, count($out));
        // Any retained long string is truncated to 200 chars (key 'long' is past
        // the cap here, so assert the truncation rule directly instead).
        $trunc = See::sanitizeExtras(['long' => str_repeat('x', 500)]);
        $this->assertSame(200, strlen($trunc['long']));
    }

    public function testSanitizeExtrasDropsNonScalarsAndNull(): void
    {
        $out = See::sanitizeExtras([
            'keep' => 'yes',
            'n' => 7,
            'f' => 1.5,
            'b' => false,
            'nada' => null,
            'arr' => [1, 2],
            'obj' => new \stdClass(),
        ]);
        $this->assertSame(['keep' => 'yes', 'n' => 7, 'f' => 1.5, 'b' => false], $out);
    }

    public function testControlFlowMarksAndReportsNothing(): void
    {
        $c = $this->capturingClient();
        $e = new \InvalidArgumentException('not a Foo');
        $c->controlFlowException($e)->because("because it wasn't an encoded Foo")->extras(['tried' => 'Foo']);
        $this->assertTrue(ExpectedRegistry::isExpected($e));
        $this->assertSame('Foo', ExpectedRegistry::get($e)['extras']['tried']);
        $this->assertCount(0, $c->posts);
    }

    public function testToIsRequiredNoSendWithoutTerminal(): void
    {
        $c = $this->capturingClient();
        $c->see(new \RuntimeException('x'))->causesThe('checkout'); // no ->to()
        $this->assertCount(0, $c->posts);
    }

    public function testToIsIdempotent(): void
    {
        $c = $this->capturingClient();
        $chain = $c->see(new \RuntimeException('x'))->causesThe('checkout');
        $chain->to('a');
        $chain->to('b');
        $this->assertCount(1, $this->events($c));
    }

    public function testDefaultsWhenConsequenceOmitted(): void
    {
        $c = $this->capturingClient();
        $c->see(new \RuntimeException('x'))->to('be incomplete');
        $ev = $this->events($c)[0];
        $this->assertSame('app', $ev['subject']);
        $this->assertSame('be incomplete', $ev['outcome']);
    }

    public function testLocalModeIsNoOp(): void
    {
        $c = Engine::forTesting();
        // forTesting can't capture posts, but it must simply not throw / not send.
        $c->see(new \RuntimeException('x'))->causesThe('checkout')->to('use cached prices');
        $this->assertTrue(true);
    }

    public function testPrivateAttributesStrippedFromExtras(): void
    {
        $c = $this->capturingClient(['secret']);
        $c->see(new \RuntimeException('x'))
            ->causesThe('checkout')
            ->extras(['secret' => 'shh', 'ok' => 'yes'])
            ->to('use cached prices');
        $ev = $this->events($c)[0];
        $this->assertArrayNotHasKey('secret', $ev['extras']);
        $this->assertSame('yes', $ev['extras']['ok']);
    }

    public function testGlobalSeeUsesLastConstructedClient(): void
    {
        // Build a capturing client, then register it as the default explicitly so
        // the package-level see() routes through our capture seam.
        $c = $this->capturingClient();
        Engine::setDefault($c);
        see(new \RuntimeException('global'))->causesThe('dashboard')->to('show cached data');
        $ev = $this->events($c)[0];
        $this->assertSame('dashboard', $ev['subject']);
        $this->assertSame('global', $ev['message']);
    }

    public function testGlobalViolationRoutesThroughDefault(): void
    {
        $c = $this->capturingClient();
        Engine::setDefault($c);
        seeViolation('global violation')->causesThe('feed')->to('be trimmed');
        $ev = $this->events($c)[0];
        $this->assertSame('violation', $ev['kind']);
        $this->assertSame('global violation', $ev['error_type']);
    }

    public function testGlobalControlFlowWorksWithoutClient(): void
    {
        // controlFlowException only stamps the throwable — no client needed.
        $e = new \LogicException('expected');
        controlFlowException($e)->because('because it is expected');
        $this->assertTrue(ExpectedRegistry::isExpected($e));
    }

    public function testNonThrowableProblemStringified(): void
    {
        $c = $this->capturingClient();
        $c->see('plain string problem')->causesThe('import')->to('skip the row');
        $ev = $this->events($c)[0];
        $this->assertSame('caught', $ev['kind']);
        $this->assertSame('Error', $ev['error_type']);
        $this->assertSame('plain string problem', $ev['message']);
        $this->assertArrayNotHasKey('stack', $ev);
    }
}
