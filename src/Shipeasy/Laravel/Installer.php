<?php

declare(strict_types=1);

namespace Shipeasy\Laravel;

/**
 * Framework-free core of `php artisan shipeasy:install`.
 *
 * Deliberately has NO Illuminate imports so it is unit-testable with plain
 * PHPUnit (the Laravel base classes are provided by the host app at runtime and
 * are never loaded in a non-Laravel context). {@see InstallCommand} is the thin
 * Artisan wrapper that delegates the env-file work here.
 */
final class Installer
{
    /**
     * Append any missing `KEY=` lines to a dotenv file, idempotently.
     *
     * A key counts as present when a line `KEY=...` (optionally surrounded by
     * whitespace) already exists — its value is left untouched. Missing keys are
     * appended as empty `KEY=` lines (the user fills in the value). A trailing
     * newline is ensured before appending so we never glue onto a half-line. If
     * the file does not exist it is created.
     *
     * @param string        $envPath Absolute path to the dotenv file (.env / .env.example).
     * @param array<string> $keys    Env var names to ensure, e.g. ['SHIPEASY_SERVER_KEY'].
     * @return array<string> The keys that were actually appended (empty if all present).
     */
    public static function ensureEnvKeys(string $envPath, array $keys): array
    {
        $contents = is_file($envPath) ? (string) file_get_contents($envPath) : '';

        $added = [];
        $append = '';
        foreach ($keys as $key) {
            if (self::hasKey($contents, $key)) {
                continue;
            }
            // Avoid double-adding the same key within one call.
            if (in_array($key, $added, true)) {
                continue;
            }
            $append .= $key . "=\n";
            $added[] = $key;
        }

        if ($added === []) {
            return [];
        }

        // Ensure the existing content ends with a newline before we append.
        if ($contents !== '' && substr($contents, -1) !== "\n") {
            $contents .= "\n";
        }
        $contents .= $append;

        file_put_contents($envPath, $contents);

        return $added;
    }

    /** True when a `KEY=` assignment already exists in the dotenv text. */
    private static function hasKey(string $contents, string $key): bool
    {
        $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=/m';
        return preg_match($pattern, $contents) === 1;
    }

    /**
     * The human-readable "next steps" block printed after install. Plain text so
     * it is testable without a console; the command echoes it line by line.
     *
     * @param bool $i18n Whether the i18n loader (`@shipeasyI18n` + client key) was requested.
     */
    public static function nextSteps(bool $i18n): string
    {
        $lines = [];
        $lines[] = 'Shipeasy installed.';
        $lines[] = '';
        $lines[] = 'Next steps:';
        $lines[] = '  1. Mint your keys: https://app.shipeasy.ai -> Settings -> SDK keys';
        $lines[] = '  2. Set them in .env:';
        $lines[] = '       SHIPEASY_SERVER_KEY=...   (server-side secret; never sent to the browser)';
        if ($i18n) {
            $lines[] = '       SHIPEASY_CLIENT_KEY=...   (public client key; used only by @shipeasyI18n)';
        }
        $lines[] = '  3. The provider auto-configures from config/shipeasy.php once';
        $lines[] = '     SHIPEASY_SERVER_KEY is set — nothing else to wire.';
        $lines[] = '  4. Place the Blade directives in your layout\'s <head>';
        $lines[] = '     (e.g. resources/views/layouts/app.blade.php):';
        $lines[] = '';
        $lines[] = '       <head>';
        $lines[] = '         @shipeasyBootstrap($user)   {{-- SSR flags/experiments bootstrap --}}';
        if ($i18n) {
            $lines[] = '         @shipeasyI18n               {{-- i18n loader (public client key) --}}';
        }
        $lines[] = '         ...';
        $lines[] = '       </head>';
        $lines[] = '';
        $lines[] = '  Read a flag anywhere per request:';
        $lines[] = '       $client = new \\Shipeasy\\Client($request->user());';
        $lines[] = '       $client->getFlag(\'new_checkout\');';
        $lines[] = '';
        $lines[] = '  Docs: https://docs.shipeasy.ai';

        return implode("\n", $lines);
    }
}
