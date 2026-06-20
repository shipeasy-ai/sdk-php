<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * Tracks which throwables have been marked expected by controlFlowException().
 * PHP exceptions are not freely mutable, so instead of stamping the object we
 * record the marker in a WeakMap keyed by the throwable — entries are dropped
 * automatically when the throwable is garbage-collected, so the registry never
 * leaks.
 */
final class ExpectedRegistry
{
    private static ?\WeakMap $map = null;

    /** @param array<string, mixed> $mark */
    public static function set(\Throwable $err, array $mark): void
    {
        self::$map ??= new \WeakMap();
        self::$map[$err] = $mark;
    }

    public static function isExpected(\Throwable $err): bool
    {
        return self::$map !== null && isset(self::$map[$err]);
    }

    /** @return array<string, mixed>|null */
    public static function get(\Throwable $err): ?array
    {
        if (self::$map === null || !isset(self::$map[$err])) {
            return null;
        }
        return self::$map[$err];
    }
}
