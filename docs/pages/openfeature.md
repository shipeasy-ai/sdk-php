# OpenFeature

This SDK ships a CNCF [OpenFeature](https://openfeature.dev) provider:
`Shipeasy\OpenFeature\ShipeasyProvider`. If your app is standardized on the
OpenFeature API, plug Shipeasy in as the backing provider — evaluation is
unchanged.

`open-feature/sdk` (`^2.0`) is an **optional** dependency; install it in your app:

```bash
composer require open-feature/sdk
```

## Wiring

```php
use OpenFeature\OpenFeatureAPI;
use Shipeasy\Engine;
use Shipeasy\OpenFeature\ShipeasyProvider;

$engine = new Engine($_ENV['SHIPEASY_SERVER_KEY']);
$engine->initOnce();

$api = OpenFeatureAPI::getInstance();
$api->setProvider(new ShipeasyProvider($engine));   // pure adapter over Engine

$of = $api->getClient();
$on = $of->getBooleanValue('new_checkout', false, $ctx);   // bool
```

`ShipeasyProvider::__construct(Engine $client)` — pass the Shipeasy `Engine`.

## Type routing

- **Booleans** evaluate gates (`getFlag`).
- **Strings / integers / floats / objects** route to dynamic configs (`getConfig`).
- The evaluation context's **targeting key** becomes the `user_id`; its
  attributes are carried through for targeting.

## Reason / error mapping

Shipeasy reasons map onto OpenFeature's `Reason` / `ErrorCode`:

| Shipeasy | OpenFeature |
| --- | --- |
| `RULE_MATCH` | `TARGETING_MATCH` |
| `OFF` | `DISABLED` |
| `OVERRIDE` | `STATIC` |
| missing flag | `FLAG_NOT_FOUND` |
| uninitialized client | `PROVIDER_NOT_READY` |
| wrong config type | `TYPE_MISMATCH` |
