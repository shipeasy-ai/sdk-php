<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Shipeasy
|--------------------------------------------------------------------------
|
| Configuration for the Shipeasy PHP SDK in a Laravel app. Publish a copy of
| this file with `php artisan vendor:publish --tag=shipeasy-config` (the
| `shipeasy:install` command does this for you), then set your keys in `.env`.
|
| The Shipeasy service provider reads this file on boot and, when a server key
| is present, calls `Shipeasy\configure()` ONCE for the process/request. You
| never call `configure()` yourself — bind a user per request instead:
|
|     $client = new Shipeasy\Client($request->user());
|     $client->getFlag('new_checkout');   // bound user, no per-call argument
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Server key (required)
    |--------------------------------------------------------------------------
    |
    | Your Shipeasy SERVER key. A server-side secret — NEVER embed it in the
    | browser. When this is empty the provider does not configure the SDK, so
    | reads (`new Shipeasy\Client(...)`) will throw until you set it.
    |
    */
    'server_key' => env('SHIPEASY_SERVER_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Client key (optional — i18n only)
    |--------------------------------------------------------------------------
    |
    | The PUBLIC client key. It is NOT passed to configure(); it is only used by
    | the `@shipeasyI18n` Blade directive to emit the i18n loader <script>. Safe
    | to expose to the browser.
    |
    */
    'client_key' => env('SHIPEASY_CLIENT_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Read environment
    |--------------------------------------------------------------------------
    |
    | Which environment's rule blob to read ('prod', 'staging', ...). Passed to
    | configure() as the `env` option.
    |
    */
    'env' => env('SHIPEASY_ENV', 'prod'),

    /*
    |--------------------------------------------------------------------------
    | Attributes transform (optional)
    |--------------------------------------------------------------------------
    |
    | Maps YOUR user model to the Shipeasy attribute map that targeting
    | evaluates against. Set this to the class name of an INVOKABLE class:
    |
    |     final class ShipeasyAttributes
    |     {
    |         public function __invoke($user): array
    |         {
    |             return ['user_id' => (string) $user->id, 'plan' => $user->plan];
    |         }
    |     }
    |
    | The provider resolves it from the container (`app()->make(...)`) and passes
    | it as the configure() attributes callable. Leave `null` for the identity
    | default — your user value is used as the attribute map unchanged.
    |
    */
    'attributes' => null,

    /*
    |--------------------------------------------------------------------------
    | i18n profile
    |--------------------------------------------------------------------------
    |
    | The locale profile the `@shipeasyI18n` loader tag requests, e.g. 'en:prod'.
    |
    */
    'i18n_profile' => env('SHIPEASY_I18N_PROFILE', 'en:prod'),

    /*
    |--------------------------------------------------------------------------
    | Log level
    |--------------------------------------------------------------------------
    |
    | The SDK's own diagnostic verbosity. One of 'silent', 'error', 'warn',
    | 'info', 'debug' (ordering silent<error<warn<info<debug; a message at level
    | L is emitted iff the configured level is >= L). Passed to configure() as the
    | `logLevel` option. Reads are fail-safe — they return the documented default
    | rather than throwing — and log at this level when something unexpected
    | happens. Defaults to 'warn'; set 'silent' to mute the SDK entirely.
    |
    */
    'log_level' => env('SHIPEASY_LOG_LEVEL', 'warn'),

    /*
    |--------------------------------------------------------------------------
    | Internal error reporting
    |--------------------------------------------------------------------------
    |
    | The SDK self-monitors: when a fail-safe read swallows one of the SDK's OWN
    | internal errors, it also ships a structured error event to Shipeasy's own
    | project (a baked-in destination + public write-only key) — distinct from
    | your see() reports, which land in YOUR Errors tab. This never touches your
    | dashboard. Set true (SHIPEASY_DISABLE_INTERNAL_ERROR_REPORTING=true) to opt
    | out. Defaults to false (reporting ON).
    |
    */
    'disable_internal_error_reporting' => env('SHIPEASY_DISABLE_INTERNAL_ERROR_REPORTING', false),

    /*
    |--------------------------------------------------------------------------
    | Network egress (master switch)
    |--------------------------------------------------------------------------
    |
    | Master on/off for ALL outbound requests the SDK makes — rule-blob fetch,
    | track(), exposures, see() reports, internal error reporting AND usage
    | telemetry. When off the SDK is fully offline: reads return your in-code
    | defaults / overrides and nothing is sent.
    |
    | Pinned to the Laravel environment: egress is ON in production and OFF
    | everywhere else, so a local/CI run never phones home unless it opts in.
    | Override with SHIPEASY_NETWORK_ENABLED=true/false, or replace
    | `app()->isProduction()` with your own condition. (Pass `null` instead to let
    | the SDK infer production itself from SHIPEASY_ENV / APP_ENV / ENV at runtime
    | — handy if you run `config:cache` in a non-production build step, since a
    | cached config freezes this value.)
    |
    */
    'network_enabled' => env('SHIPEASY_NETWORK_ENABLED', app()->isProduction()),

];
