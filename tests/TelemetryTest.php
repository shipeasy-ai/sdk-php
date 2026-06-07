<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Telemetry;

final class TelemetryTest extends TestCase
{
    // 1) basic telemetry send works for each entity call, hitting the right URL.
    public function testFiresPerEntity(): void
    {
        $t = new class ('https://e.x', 'srv') extends Telemetry {
            /** @var array<int, string> */
            public array $captured = [];
            protected function dispatch(string $url): void
            {
                $this->captured[] = $url;
            }
        };

        $t->emit('gate', 'g');
        $t->emit('config', 'c');
        $t->emit('experiment', 'e');
        $t->emit('ks', 'k');

        $this->assertCount(4, $t->captured);
        $this->assertStringEndsWith('/gate/g', $t->captured[0]);
        $this->assertStringEndsWith('/config/c', $t->captured[1]);
        $this->assertStringEndsWith('/experiment/e', $t->captured[2]);
        $this->assertStringEndsWith('/ks/k', $t->captured[3]);
        foreach ($t->captured as $url) {
            $this->assertStringStartsWith('https://e.x/t/', $url);
            $this->assertStringNotContainsString('srv', $url); // raw key never in URL
        }
    }

    // 2) telemetry is not sent when disabled in settings.
    public function testDisabledSendsNothing(): void
    {
        $t = new class ('https://e.x', 'srv', 'server', 'prod', true) extends Telemetry {
            /** @var array<int, string> */
            public array $captured = [];
            protected function dispatch(string $url): void
            {
                $this->captured[] = $url;
            }
        };

        $t->emit('gate', 'g');
        $t->emit('config', 'c');
        $t->emit('experiment', 'e');

        $this->assertCount(0, $t->captured);
    }
}
