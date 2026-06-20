<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * Per-process spam guard for see(): identical events within 30s collapse to one
 * send; a hard cap bounds total sends per process. The worker dedupes by
 * fingerprint anyway — this only bounds network chatter from a hot loop.
 */
final class SeeLimiter
{
    private int $max;
    private int $window;
    /** @var array<string, int> */
    private array $last = [];
    private int $sent = 0;

    public function __construct(
        int $maxPerProcess = SeeLimits::MAX_PER_PROCESS,
        int $dedupWindowMs = SeeLimits::DEDUP_WINDOW_MS
    ) {
        $this->max = $maxPerProcess;
        $this->window = $dedupWindowMs;
    }

    /** @param array<string, mixed> $ev */
    public function shouldSend(array $ev): bool
    {
        if ($this->sent >= $this->max) {
            return false;
        }
        $key = implode('|', [
            (string) ($ev['kind'] ?? ''),
            (string) ($ev['error_type'] ?? ''),
            mb_substr((string) ($ev['message'] ?? ''), 0, 200),
            self::topStackLine($ev['stack'] ?? null),
        ]);
        $now = (int) (microtime(true) * 1000);
        $prev = $this->last[$key] ?? null;
        if ($prev !== null && $now - $prev < $this->window) {
            return false;
        }
        $this->last[$key] = $now;
        $this->sent++;
        return true;
    }

    private static function topStackLine(?string $stack): string
    {
        if (!$stack) {
            return '';
        }
        foreach (preg_split('/\r?\n/', $stack) as $line) {
            $s = trim($line);
            if ($s === '') {
                continue;
            }
            // PHP traces look like "#0 /path/file.php(12): foo()".
            return mb_substr($s, 0, 200);
        }
        return '';
    }
}
