<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * controlFlowException($e)->because("because ...") — marks the throwable as
 * expected control flow and reports NOTHING. ->extras() (on the returned tail)
 * is stored on the marker for local debugging only; an expected exception is
 * never transmitted.
 */
final class ControlFlowChain
{
    private \Throwable $err;

    public function __construct(\Throwable $err)
    {
        $this->err = $err;
    }

    public function because(string $reason): ControlFlowTail
    {
        self::markExpected($this->err, $reason, null);
        return new ControlFlowTail($this->err, $reason);
    }

    /**
     * Best-effort stamp marking a throwable as expected control flow.
     *
     * @param array<string, mixed>|null $extras
     */
    public static function markExpected(\Throwable $err, string $reason, ?array $extras): void
    {
        try {
            $mark = ['because' => $reason];
            $clean = See::sanitizeExtras($extras);
            if ($clean !== null) {
                $mark['extras'] = $clean;
            }
            ExpectedRegistry::set($err, $mark);
        } catch (\Throwable) {
            // Best effort only.
        }
    }
}
