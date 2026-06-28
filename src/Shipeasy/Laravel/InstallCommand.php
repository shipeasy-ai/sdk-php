<?php

declare(strict_types=1);

namespace Shipeasy\Laravel;

use Illuminate\Console\Command;

/**
 * `php artisan shipeasy:install`
 *
 * Scaffolds Shipeasy into a Laravel app the Laravel way:
 *
 *   - publishes config/shipeasy.php (the single source the provider reads)
 *   - ensures SHIPEASY_SERVER_KEY= (and, with --i18n, SHIPEASY_CLIENT_KEY=)
 *     exist in .env and .env.example
 *   - prints where to place the @shipeasyBootstrap / @shipeasyI18n Blade
 *     directives and a reminder to set the keys
 *
 * The service provider already registers the Blade directives and
 * auto-configures from config — so this command never wires those. It only
 * creates what the app must own: the published config, the env placeholders,
 * and (by hand, the Laravel way) the layout directives.
 *
 * Honors "don't invent": we do NOT codegen into a guessed Blade layout file —
 * Laravel packages ship directives the user PLACES, so we tell them where.
 *
 * Extends Illuminate\Console\Command, which the HOST Laravel app provides at
 * runtime (PSR-4 autoload is lazy — this file never loads outside Laravel).
 */
class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'shipeasy:install {--i18n : Also wire the i18n loader (client key + @shipeasyI18n)} {--force : Overwrite an existing published config}';

    /** @var string */
    protected $description = 'Install Shipeasy: publish config/shipeasy.php, seed .env keys, and print Blade-directive next steps.';

    public function handle(): int
    {
        $i18n = (bool) $this->option('i18n');
        $force = (bool) $this->option('force');

        // 1. Publish config/shipeasy.php.
        $this->callSilent('vendor:publish', [
            '--tag' => 'shipeasy-config',
            '--force' => $force,
        ]);
        $this->info('Published config/shipeasy.php');

        // 2. Ensure the env placeholders exist in .env and .env.example.
        $keys = ['SHIPEASY_SERVER_KEY'];
        if ($i18n) {
            $keys[] = 'SHIPEASY_CLIENT_KEY';
        }

        $base = $this->envBasePath();
        foreach (['.env', '.env.example'] as $file) {
            $path = $base . DIRECTORY_SEPARATOR . $file;
            // Don't create a .env.example out of nowhere, but always seed .env.
            if ($file === '.env.example' && !is_file($path)) {
                continue;
            }
            $added = Installer::ensureEnvKeys($path, $keys);
            if ($added !== []) {
                $this->line("Added " . implode(', ', $added) . " to {$file}");
            }
        }

        // 3. Print the next steps (Blade directive placement + keys reminder).
        $this->line('');
        foreach (explode("\n", Installer::nextSteps($i18n)) as $line) {
            $this->line('  ' . $line);
        }
        $this->line('');

        return self::SUCCESS;
    }

    /** Base directory for the dotenv files — the Laravel app base path. */
    private function envBasePath(): string
    {
        if (function_exists('base_path')) {
            return base_path();
        }
        // Fallback for non-standard hosts (kept defensive; Laravel always
        // provides base_path()).
        return getcwd() ?: '.';
    }
}
