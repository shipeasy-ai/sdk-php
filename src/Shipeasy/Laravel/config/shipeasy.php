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

];
