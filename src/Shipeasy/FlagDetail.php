<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * The outcome of a flag evaluation plus the reason it resolved that way.
 * Returned by Engine::getFlagDetail(). The `reason` is one of the class
 * constants below — it explains whether the value came from an override, a
 * matched rule/rollout, an off/missing gate, or an uninitialized client.
 */
final class FlagDetail
{
    /** Client was not initialized — the flags blob has not been fetched yet. */
    public const CLIENT_NOT_READY = 'CLIENT_NOT_READY';
    /** The gate is not present in the fetched blob. */
    public const FLAG_NOT_FOUND = 'FLAG_NOT_FOUND';
    /** The gate exists but is disabled (its `enabled` field is not set). */
    public const OFF = 'OFF';
    /** A local override (overrideFlag) supplied the value. */
    public const OVERRIDE = 'OVERRIDE';
    /** The gate evaluated to true — a rule/rollout matched. */
    public const RULE_MATCH = 'RULE_MATCH';
    /** The gate evaluated to false — no rule/rollout matched. */
    public const DEFAULT = 'DEFAULT';

    public function __construct(
        public readonly bool $value,
        public readonly string $reason,
    ) {}
}
