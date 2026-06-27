# Installation

## Requirements

- **PHP 8.1+**
- Extensions: `ext-json`, `ext-curl` (both required)
- Hosts: plain PHP, Laravel, WordPress, Symfony, Slim — anything PHP-FPM,
  Swoole, or RoadRunner can serve.

## Install

```bash
composer require shipeasy/shipeasy
```

The package is `shipeasy/shipeasy` on Packagist.

## Imports

The package registers a Composer `files` autoload, so the package-level
functions (`Shipeasy\configure`, `Shipeasy\see`, …) are available after
`require 'vendor/autoload.php'`.

```php
require 'vendor/autoload.php';

use function Shipeasy\configure;   // configure-once front door
use Shipeasy\Client;               // lightweight, user-bound handle
use Shipeasy\Engine;               // heavyweight engine (advanced/tests)
```

## Optional dependencies

- **OpenFeature** — `open-feature/sdk` (`^2.0`) is an optional dependency. Install
  it in your app to use `Shipeasy\OpenFeature\ShipeasyProvider`:
  ```bash
  composer require open-feature/sdk
  ```

## Next

Head to [Configuration](configuration.md) to wire up `configure()`.
