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

Call `Shipeasy\configure()` first, then construct `new ShipeasyProvider()` with
**no argument** — the global form resolves the SDK that `configure()` set up:

```php
use OpenFeature\OpenFeatureAPI;
use Shipeasy\OpenFeature\ShipeasyProvider;
use function Shipeasy\configure;

configure($_ENV['SHIPEASY_SERVER_KEY']);   // once, at startup

$api = OpenFeatureAPI::getInstance();
$api->setProvider(new ShipeasyProvider());   // no-arg global form

$of = $api->getClient();
$on = $of->getBooleanValue('new_checkout', false, $ctx);   // bool
```

Constructing `new ShipeasyProvider()` before `configure()` throws
`RuntimeException`.

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
| `DEFAULT` | `DEFAULT` |
| `OFF` | `DISABLED` |
| `OVERRIDE` | `STATIC` |
| missing flag | `FLAG_NOT_FOUND` |
| uninitialized client | `PROVIDER_NOT_READY` |
| wrong config type | `TYPE_MISMATCH` |
