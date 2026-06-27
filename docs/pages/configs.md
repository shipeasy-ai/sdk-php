# Dynamic configs

A **dynamic config** is a typed JSON value (string, number, array/object)
managed in the dashboard. Configs are **not user-scoped** in this SDK — they are
read by name.

## Bound `Client` form

```php
use Shipeasy\Client;

$client = new Client($currentUser);

$copy = $client->getConfig('billing_copy');
// e.g. ['headline' => 'Welcome', 'cta' => 'Upgrade']

// With a fallback used when the key is absent:
$copy = $client->getConfig('billing_copy', ['headline' => 'Welcome']);
```

## Low-level `Engine` form

```php
$copy = $engine->getConfig('billing_copy');
$copy = $engine->getConfig('billing_copy', ['headline' => 'Welcome']);
```

## Default behaviour

`getConfig($name)` returns `null` when the config key is absent. Pass a second
argument to supply your own fallback for the absent case:

```php
$c->getConfig('billing_copy', ['headline' => 'Welcome']);
```

The default is returned **only** when the config key is not present in the
fetched blob.
