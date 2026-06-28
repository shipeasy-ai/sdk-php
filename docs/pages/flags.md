# Flags

A **flag** (gate) is a boolean that resolves per user against rollout +
targeting rules defined in the Shipeasy dashboard.

## Read a flag

The user is bound once at `new Client($user)`; `getFlag` takes only the name and
an optional default:

```php
use Shipeasy\Client;

$client  = new Client($currentUser);                 // construct once per callsite
$enabled = $client->getFlag('new_checkout');         // default false
$enabled = $client->getFlag('new_checkout', true);   // default true
```

Assumes `Shipeasy\configure()` ran at startup — see [Installation](installation.md).

## Boolean semantics + default behaviour

The optional default is returned **only when the gate cannot be evaluated** —
NOT when it simply evaluates false:

- gate is off, or its rules/rollout don't match the user → `false` (NOT the default)
- gate not in the fetched blob (`FLAG_NOT_FOUND`) → the default
- SDK never initialized (`CLIENT_NOT_READY`) → the default

When the second argument is omitted the default is `false`.

## Evaluation detail

`getFlagDetail()` returns a `Shipeasy\FlagDetail` with `->value` (bool) and
`->reason` explaining how the flag resolved:

```php
$client = new Client($currentUser);   // construct once per callsite
$d      = $client->getFlagDetail('new_checkout');

$d->value;    // bool
$d->reason;   // e.g. FlagDetail::RULE_MATCH
```

| Reason | Meaning |
| --- | --- |
| `FlagDetail::OVERRIDE` | A local `Shipeasy\overrideFlag()` supplied the value. |
| `FlagDetail::CLIENT_NOT_READY` | The SDK never initialized (no blob fetched). |
| `FlagDetail::FLAG_NOT_FOUND` | The gate is not in the fetched blob. |
| `FlagDetail::OFF` | The gate exists but is disabled. |
| `FlagDetail::RULE_MATCH` | The gate evaluated **true** (a rule/rollout matched). |
| `FlagDetail::DEFAULT` | The gate evaluated **false** (nothing matched). |

`getFlag()` is implemented on top of `getFlagDetail()`. The usage-telemetry
beacon fires exactly once per `getFlagDetail()` call — and never for an override.
