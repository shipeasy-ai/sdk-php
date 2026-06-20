<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * Limits for see() structured error reporting. Mirror core.ts; kept in sync
 * with the worker's /collect ingest caps.
 */
final class SeeLimits
{
    public const MAX_MESSAGE = 500;
    public const MAX_STACK = 8000;
    public const MAX_SUBJECT = 200; // subject, outcome, error_type
    public const MAX_EXTRA_VALUE = 200;
    public const MAX_EXTRA_KEYS = 20;
    public const DEDUP_WINDOW_MS = 30000;
    public const MAX_PER_PROCESS = 25;

    public const DEFAULT_SUBJECT = 'app';
    public const DEFAULT_OUTCOME = 'hit an error';
}
