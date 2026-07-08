<?php

declare(strict_types=1);

namespace Shipeasy\Laravel;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel service provider for the Shipeasy PHP SDK.
 *
 * Auto-discovered via `extra.laravel.providers` in composer.json (no manual
 * registration needed on Laravel 5.5+). It:
 *
 *   - merges + publishes config/shipeasy.php,
 *   - registers `php artisan shipeasy:install`,
 *   - auto-configures the SDK from config (calls `\Shipeasy\configure()` once
 *     when a server key is present — first-config-wins, so it's safe), and
 *   - registers the `@shipeasyBootstrap` / `@shipeasyI18n` Blade directives the
 *     user places in their layout <head>.
 *
 * Extends Illuminate\Support\ServiceProvider, supplied by the host Laravel app
 * at runtime; this file is never autoloaded outside a Laravel context.
 */
class ShipeasyServiceProvider extends ServiceProvider
{
    private const CONFIG = __DIR__ . '/config/shipeasy.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'shipeasy');
    }

    public function boot(): void
    {
        $this->publishes([
            self::CONFIG => $this->configPath('shipeasy.php'),
        ], 'shipeasy-config');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }

        $this->configureSdk();
        $this->registerBladeDirectives();
    }

    /**
     * Call \Shipeasy\configure() from config when a server key is set.
     * first-config-wins makes a duplicate (e.g. a hand-written call) harmless.
     */
    private function configureSdk(): void
    {
        if (!function_exists('\Shipeasy\configure')) {
            return;
        }

        $key = $this->config('shipeasy.server_key');
        if (!is_string($key) || $key === '') {
            return;
        }

        $transform = $this->resolveAttributes($this->config('shipeasy.attributes'));

        $opts = [
            'env' => (string) ($this->config('shipeasy.env') ?? 'prod'),
            'logLevel' => (string) ($this->config('shipeasy.log_level') ?? 'warn'),
            'disableInternalErrorReporting' => (bool) $this->config('shipeasy.disable_internal_error_reporting'),
        ];

        // Master network egress switch. Only forward it when explicitly set so an
        // absent config falls through to the environment-derived default (egress
        // on in prod, off elsewhere — see Shipeasy\Env).
        $networkEnabled = $this->config('shipeasy.network_enabled');
        if ($networkEnabled !== null) {
            $opts['isNetworkEnabled'] = (bool) $networkEnabled;
        }

        \Shipeasy\configure($key, $transform, $opts);
    }

    /**
     * Resolve the configured `attributes` transform into a callable, or null for
     * the identity default. Accepts an invokable class name (resolved from the
     * container) or a plain callable.
     *
     * @param mixed $attributes
     */
    private function resolveAttributes(mixed $attributes): ?callable
    {
        if ($attributes === null) {
            return null;
        }
        if (is_callable($attributes)) {
            return $attributes;
        }
        if (is_string($attributes) && class_exists($attributes)) {
            $instance = $this->app->make($attributes);
            if (is_callable($instance)) {
                return $instance;
            }
        }
        return null;
    }

    /**
     * Register the layout helpers the user places in their Blade <head>:
     *   @shipeasyBootstrap($user) — SSR flags/experiments bootstrap tag
     *   @shipeasyI18n             — i18n loader tag (public client key + profile)
     */
    private function registerBladeDirectives(): void
    {
        if (!class_exists(Blade::class)) {
            return;
        }

        Blade::directive('shipeasyBootstrap', static function (string $expression): string {
            $arg = trim($expression) === '' ? '[]' : $expression;
            return "<?php echo \\Shipeasy\\bootstrapScriptTag({$arg}); ?>";
        });

        Blade::directive('shipeasyI18n', static function (string $expression): string {
            // Reads the client key + profile from config at render time.
            return "<?php echo \\Shipeasy\\i18nScriptTag("
                . "(string) config('shipeasy.client_key', ''), "
                . "(string) config('shipeasy.i18n_profile', 'en:prod')); ?>";
        });
    }

    /** Read a config value, tolerating the absence of the global helper. */
    private function config(string $key): mixed
    {
        if (function_exists('config')) {
            return config($key);
        }
        return $this->app['config']->get($key) ?? null;
    }

    /** config_path() with a defensive fallback for odd hosts. */
    private function configPath(string $file): string
    {
        if (function_exists('config_path')) {
            return config_path($file);
        }
        return $this->app->basePath('config' . DIRECTORY_SEPARATOR . $file);
    }
}
