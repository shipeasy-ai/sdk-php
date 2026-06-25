<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * Fluent chain returned by Engine::see()/seeViolation(). Accumulates the
 * consequence (subject) + extras; ->to($outcome) is the terminal that builds the
 * event and fire-and-forgets the report.
 *
 * Dispatch model (differs from TS, which uses a microtask): ->to($outcome) is
 * the terminal. causesThe() and extras() are chainable setters callable in any
 * order *before* ->to(). The bound dispatch callable receives
 * ($problem, $subject, $outcome, $extras).
 *
 *     $client->see($e)->causesThe("checkout")->extras(["order_id" => $id])
 *         ->to("use cached prices");
 */
final class SeeChain
{
    private mixed $problem;
    /** @var callable */
    private $dispatch;
    private ?string $subject = null;
    private ?string $outcome = null;
    /** @var array<string, mixed>|null */
    private ?array $extras = null;
    private bool $done = false;

    public function __construct(mixed $problem, callable $dispatch)
    {
        $this->problem = $problem;
        $this->dispatch = $dispatch;
    }

    public function causesThe(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /** @param array<string, mixed> $extras merged on repeat (later wins). */
    public function extras(array $extras): self
    {
        if ($extras !== []) {
            $this->extras = array_merge($this->extras ?? [], $extras);
        }
        return $this;
    }

    /** Terminal: build the event and fire-and-forget the report. Idempotent. */
    public function to(string $outcome): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        $this->outcome = $outcome;
        try {
            ($this->dispatch)(
                $this->problem,
                $this->subject ?? SeeLimits::DEFAULT_SUBJECT,
                $this->outcome !== '' ? $this->outcome : SeeLimits::DEFAULT_OUTCOME,
                $this->extras
            );
        } catch (\Throwable) {
            // Reporting must never raise into caller code.
        }
    }
}
