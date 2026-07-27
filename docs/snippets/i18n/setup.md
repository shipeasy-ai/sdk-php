i18n is rendered in the browser. From PHP, emit the loader `<script>` tag with
the **public client key** and the `{{PROFILE}}` profile into your `<head>`.
Assumes `Shipeasy\configure()` ran at startup — see Installation.

```php
use function Shipeasy\bootstrapScriptTag;
use function Shipeasy\devtoolsScriptTag;
use function Shipeasy\i18nScriptTag;

// Package-level helpers — backed by the SDK that configure() set up. EVERY
// argument is optional: the PUBLIC client key, profile and CDN origin come from
// configure(['clientKey' => ..., 'profile' => '{{PROFILE}}', 'projectId' => ...]).
$head = bootstrapScriptTag(
            ['user_id' => 'u_123'],       // optional: the request's user/attribute map
            ['anonId' => $anonId],        // optional opts: anonId, i18nProfile, baseUrl
        )
      . i18nScriptTag();                  // optional: ($clientKey, $profile, $opts)

// Devtools overlay (Shift+Alt+S or ?se=1) — opens only for a signed-in
// Shipeasy session, so gating it on staff/env is optional.
// optional: ($projectId, ['clientKey' => ..., 'baseUrl' => ..., 'defer' => true])
$head .= devtoolsScriptTag();
```
