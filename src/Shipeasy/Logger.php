<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * Tiny leveled logger for the SDK's own diagnostics — the PHP analog of the TS
 * SDK's leveled logger. A single process-wide level gates emission: a message
 * at level L is emitted iff the configured level is >= L, on the ordering
 *
 *     silent < error < warn < info < debug
 *
 * So `silent` mutes everything, `error` shows only errors, the default `warn`
 * shows errors + warnings, and `debug` shows everything. Unknown level strings
 * fall back to `warn`.
 *
 * The level is set from {@see Engine::__construct()} via {@see setLevel()}
 * (driven by the `logLevel` option / `SHIPEASY_LOG_LEVEL`). Emission goes
 * through `error_log('[shipeasy] …')` and NEVER throws — a logger that raised
 * into the caller would defeat the whole fail-safe design.
 */
final class Logger
{
    /** Numeric rank per level; higher = more verbose. */
    private const RANKS = [
        'silent' => 0,
        'error' => 1,
        'warn' => 2,
        'info' => 3,
        'debug' => 4,
    ];

    /** The configured level. Default 'warn' (matches Engine's default). */
    private static string $level = 'warn';

    /**
     * Set the process-wide log level. Unknown values fall back to 'warn'.
     */
    public static function setLevel(?string $level): void
    {
        $level = is_string($level) ? strtolower($level) : '';
        self::$level = array_key_exists($level, self::RANKS) ? $level : 'warn';
    }

    /** The current level (normalised). */
    public static function level(): string
    {
        return self::$level;
    }

    public static function error(string $msg): void
    {
        self::emit('error', $msg);
    }

    public static function warn(string $msg): void
    {
        self::emit('warn', $msg);
    }

    public static function info(string $msg): void
    {
        self::emit('info', $msg);
    }

    public static function debug(string $msg): void
    {
        self::emit('debug', $msg);
    }

    /**
     * Emit $msg at $level iff the configured level allows it. Never throws.
     */
    private static function emit(string $level, string $msg): void
    {
        $want = self::RANKS[$level] ?? self::RANKS['warn'];
        $have = self::RANKS[self::$level] ?? self::RANKS['warn'];
        if ($have < $want) {
            return;
        }
        try {
            error_log('[shipeasy] ' . $msg);
        } catch (\Throwable) {
            // Logging must never raise into caller code.
        }
    }
}
