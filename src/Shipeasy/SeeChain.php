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
 * order *before* ->to(). ->to() also accepts the extras inline as a second arg,
 * so there is no ordering trap. The bound dispatch callable receives
 * ($problem, $subject, $outcome, $extras).
 *
 *     $client->see($e)->causesThe("checkout")->extras(["order_id" => $id])
 *         ->to("use cached prices");
 *     $client->see($e)->causesThe("checkout")
 *         ->to("use cached prices", ["order_id" => $id]);
 *
 * An ->extras(...) chained AFTER ->to(...) is ignored with a warning (the report
 * already shipped) — it never throws into the caller's catch block. To attach
 * context from anywhere in a request without threading it into the catch, use
 * the ambient buffer via Shipeasy\addExtras() (see {@see SeeContext}).
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

    /**
     * Attach debugging metadata. Chainable — call repeatedly (keys merge, later
     * wins) *before* ->to(). Called AFTER ->to() it is a no-op with a warning:
     * the report already shipped, so there is nothing to amend and — crucially —
     * it must not throw into the caller's catch block. Use ->to($outcome, $extras)
     * or Shipeasy\addExtras() for late/scattered context instead.
     *
     * @param array<string, mixed> $extras merged on repeat (later wins).
     */
    public function extras(array $extras): self
    {
        if ($this->done) {
            Logger::warn(
                'see() ->extras(...) called after ->to(...) is ignored — '
                . 'pass extras to ->to($outcome, $extras) or call ->extras before ->to'
            );
            return $this;
        }
        if ($extras !== []) {
            $this->extras = array_merge($this->extras ?? [], $extras);
        }
        return $this;
    }

    /**
     * Terminal: build the event and fire-and-forget the report. Idempotent.
     * $extras may be passed inline here as the trailing form
     * ->to($outcome, ["order_id" => $id]) — merged like a final ->extras() call.
     * Returns $this so a stray trailing ->extras() chains harmlessly.
     *
     * @param array<string, mixed>|null $extras
     */
    public function to(string $outcome, ?array $extras = null): self
    {
        if ($this->done) {
            return $this;
        }
        if ($extras !== null && $extras !== []) {
            $this->extras = array_merge($this->extras ?? [], $extras);
        }
        $this->done = true;
        $this->outcome = $outcome;
        try {
            ($this->dispatch)(
                $this->problem,
                $this->subject ?? SeeLimits::DEFAULT_SUBJECT,
                $this->outcome !== '' ? $this->outcome : SeeLimits::DEFAULT_OUTCOME,
                $this->resolvedExtras()
            );
        } catch (\Throwable) {
            // Reporting must never raise into caller code.
        }
        return $this;
    }

    /**
     * The chain's own extras merged OVER the ambient per-request buffer, so a
     * chained key of the same name wins over an ambient one. Returns null when
     * both are empty (the dispatcher treats null as "no extras").
     *
     * @return array<string, mixed>|null
     */
    private function resolvedExtras(): ?array
    {
        $ambient = SeeContext::current();
        if ($ambient === []) {
            return $this->extras;
        }
        if ($this->extras === null || $this->extras === []) {
            return $ambient;
        }
        return array_merge($ambient, $this->extras);
    }
}
