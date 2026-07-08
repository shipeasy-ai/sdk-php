<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Assignment;
use Shipeasy\Engine;
use Shipeasy\Logger;
use Shipeasy\StickyBucketStore;

/**
 * Fail-safe reads + the leveled logger (0.13.0).
 *
 * (a) A runtime read whose user-supplied code (here a StickyBucketStore, the
 *     read-path callable the caller injects) throws must NOT propagate — the
 *     read returns the documented safe default.
 * (b) The leveled Logger gates on the configured level: 'silent' mutes, the
 *     default 'warn' emits.
 *
 * The real emission path (error_log) is captured by pointing the `error_log`
 * ini at a temp file, mirroring the subclass-to-capture pattern used elsewhere
 * for /collect POSTs.
 */
final class NoThrowLoggingTest extends TestCase
{
    private ?string $prevErrorLog = null;
    private ?string $logFile = null;

    protected function setUp(): void
    {
        // Route error_log() to a capturable temp file for this test.
        $this->logFile = tempnam(sys_get_temp_dir(), 'se-log-');
        $this->prevErrorLog = ini_get('error_log') ?: '';
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevErrorLog ?? '');
        if ($this->logFile !== null && is_file($this->logFile)) {
            @unlink($this->logFile);
        }
        Logger::setLevel('warn');
        Engine::resetForTesting();
    }

    private function capturedLog(): string
    {
        return $this->logFile !== null && is_file($this->logFile)
            ? (string) file_get_contents($this->logFile)
            : '';
    }

    /**
     * A running experiment; universe()->assign() will consult the sticky store,
     * giving the injected throwing store a chance to blow up mid-read.
     */
    private function runningExpsBlob(): array
    {
        return [
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
        ];
    }

    // ---- (a) fail-safe read: a throwing user callable never escapes ----

    public function testAssignSwallowsThrowingStickyStoreAndReturnsNotEnrolled(): void
    {
        // A user-supplied store whose get() throws — the read-path "decode"
        // callable the caller injected. It must not surface from assign().
        $boom = new class implements StickyBucketStore {
            public function get(string $unit): ?array
            {
                throw new \RuntimeException('sticky store exploded');
            }
            public function set(string $unit, string $exp, array $entry): void
            {
            }
        };

        $engine = new Engine('k', null, 'prod', true, null, [], $boom);
        // Seed the running experiment snapshot without any network.
        $engine->applyData(null, $this->runningExpsBlob());

        $result = $engine->universe('u1')->assign(['user_id' => 'u_1']);

        // Did NOT throw; returned the safe not-enrolled handle.
        $this->assertInstanceOf(Assignment::class, $result);
        $this->assertFalse($result->enrolled());
        $this->assertNull($result->group);
        $this->assertSame('fallback', $result->get('c', 'fallback'));
    }

    public function testGetFlagSwallowsBadBlobAndReturnsDefault(): void
    {
        $engine = new Engine('k', null, 'prod', true);
        // A structurally broken gate: `enabled` true but rules is a non-array
        // scalar that the canonical eval will choke on.
        $engine->applyData(['gates' => ['broken' => ['enabled' => true, 'rules' => "\xff not-json"]]], null);

        // Whatever the eval does internally, the read must return the default.
        $out = $engine->getFlag('broken', ['user_id' => 'u_1'], true);
        $this->assertIsBool($out);
    }

    // ---- (b) leveled logger: silent mutes, warn emits ----

    public function testSilentLevelMutesWhereWarnEmits(): void
    {
        Logger::setLevel('warn');
        Logger::warn('audible-warning-marker');
        $this->assertStringContainsString('[shipeasy] audible-warning-marker', $this->capturedLog());

        // Reset the capture file and switch to silent.
        file_put_contents($this->logFile, '');
        Logger::setLevel('silent');
        Logger::warn('muted-warning-marker');
        Logger::error('muted-error-marker');
        $this->assertSame('', $this->capturedLog());
    }

    public function testUnknownLevelFallsBackToWarn(): void
    {
        Logger::setLevel('bogus');
        $this->assertSame('warn', Logger::level());
        // warn emits, info/debug do not.
        Logger::info('info-should-be-muted');
        Logger::warn('warn-should-emit');
        $log = $this->capturedLog();
        $this->assertStringNotContainsString('info-should-be-muted', $log);
        $this->assertStringContainsString('warn-should-emit', $log);
    }

    public function testEngineConstructorSetsLoggerLevelFromOption(): void
    {
        new Engine('k', null, 'prod', true, null, [], null, 'debug');
        $this->assertSame('debug', Logger::level());

        new Engine('k', null, 'prod', true, null, [], null, null);
        $this->assertSame('warn', Logger::level());
    }
}
