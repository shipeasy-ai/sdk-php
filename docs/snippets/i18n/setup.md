i18n is rendered in the browser. From PHP, emit the loader `<script>` tag with
the **public client key** and the `{{PROFILE}}` profile into your `<head>`.
Assumes `configure()` ran at startup — see Installation.

```php
use Shipeasy\Engine;

// the configured engine (the default registered at startup).
// Resolve once per request, not per tag.
$engine = Engine::getDefault();

// $clientKey is the PUBLIC client key (NOT the server key).
$head = $engine->bootstrapScriptTag(
            ['user_id' => 'u_123'],       // the request's evaluated user/attribute map
            ['anonId' => $anonId],        // optional opts: anonId, i18nProfile, baseUrl
        )
      . $engine->i18nScriptTag(
            $clientKey,                    // PUBLIC client key — embedded in the page
            '{{PROFILE}}',                 // locale profile to load (e.g. en:prod)
        );
```
