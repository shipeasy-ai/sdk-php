<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * Tail of controlFlowException($e)->because(...). ->extras() stores debug
 * context on the marker; it is never transmitted.
 */
final class ControlFlowTail
{
    private \Throwable $err;
    private string $reason;

    public function __construct(\Throwable $err, string $reason)
    {
        $this->err = $err;
        $this->reason = $reason;
    }

    /** @param array<string, mixed> $extras stored for local debug only; never sent. */
    public function extras(array $extras): self
    {
        ControlFlowChain::markExpected($this->err, $this->reason, $extras);
        return $this;
    }
}
