i18n is rendered in the browser. From PHP, emit the loader `<script>` tag with
the **public client key** and the `{{PROFILE}}` profile into your `<head>`.
Assumes `Shipeasy\configure()` ran at startup — see Installation.

```php
use function Shipeasy\bootstrapScriptTag;
use function Shipeasy\i18nScriptTag;

// Package-level helpers — backed by the SDK that configure() set up.
// $clientKey is the PUBLIC client key (NOT the server key).
$head = bootstrapScriptTag(
            ['user_id' => 'u_123'],       // the request's evaluated user/attribute map
            ['anonId' => $anonId],        // optional opts: anonId, i18nProfile, baseUrl
        )
      . i18nScriptTag(
            $clientKey,                    // PUBLIC client key — embedded in the page
            '{{PROFILE}}',                 // locale profile to load (e.g. en:prod)
        );
```
