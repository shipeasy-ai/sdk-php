i18n is rendered in the browser. From PHP, emit the loader `<script>` tag with
the **public client key** and the `{{PROFILE}}` profile into your `<head>`.

```php
// $clientKey is the PUBLIC client key (not the server key).
$head = $engine->bootstrapScriptTag(['user_id' => 'u_123'], ['anonId' => $anonId])
      . $engine->i18nScriptTag($clientKey, '{{PROFILE}}');
```
