# Admin API client (optional) — `Shipeasy\Admin`

The base SDK *evaluates* flags, configs, and experiments
([`Shipeasy\configure()`](configuration.md) + `new Shipeasy\Client($user)`). The
**Admin API client** is a separate, optional surface for *administering* a small,
deliberate slice of those resources from server code.

It is **intentionally lean** — four capabilities, eight operations, not the
whole admin API:

| Capability           | Operations                                                                               |
| -------------------- | ---------------------------------------------------------------------------------------- |
| List the ops queue   | `listOpsItems` |
| File a public ticket (client key) | `createPublicBug`, `createPublicFeatureRequest`                                           |
| Toggle a kill switch | `toggleKillswitch`                                                                        |
| Manage a whitelist   | `getGateWhitelist`, `setGateWhitelist`, `addToGateWhitelist`, `removeFromGateWhitelist`   |

Everything else in the admin API — generic CRUD, experiments, metrics,
events, configs, i18n, projects, connectors, keys — is deliberately **not** here.
Reach for the Shipeasy CLI or MCP for those; they speak the complete spec.
Keeping the vendored contract small is what keeps the generated client small.

It is **off by default**: `guzzlehttp/guzzle` is a composer `suggest`, not a hard
dependency of the base SDK. Install it to use the admin client:

```bash
composer require guzzlehttp/guzzle
```

The client is **generated from the Shipeasy OpenAPI spec**, so it is a raw, 1:1
projection of the REST API: id-based paths and typed request models. It does
*not* add the percent→basis-point conveniences you get from the Shipeasy
CLI/MCP — reach for those tools when you want the ergonomic surface, and for this
client when you want a typed, programmatic mirror of the API.

## Authenticate and scope

Mint an **admin** SDK key (`sdk_admin_…`) and scope every call to a project.

```php
$admin = new \Shipeasy\Admin\AdminClient(
    getenv('SHIPEASY_ADMIN_KEY'),      // Authorization: Bearer <key>
    getenv('SHIPEASY_PROJECT_ID'),     // sent as X-Project-Id on every call
    'https://shipeasy.ai',             // admin API host
    // Only needed for the two public ticket operations — see "File a public
    // ticket" below. They send it as X-SDK-Key, on the edge host.
    getenv('SHIPEASY_CLIENT_KEY') ?: null,
);
```

The project id is sent as the `X-Project-Id` header on every request. It is
optional on the constructor — individual operations also accept an explicit
`$x_project_id` argument to override per call.

## File a public ticket

Two dedicated endpoints, so there is no discriminator to set and no fields from
the other kind in the request. Both accept just a `title`.

These two are the **public** intake, and they differ from the other six
operations in three ways worth knowing before you call them:

- They authenticate with a **client** key (`sdk_client_…`) carrying the
  `tickets:public_create` scope — not the admin key. Client keys are meant to be
  embedded in shipped code, which is the point: a CLI, an installer, or a
  browser bundle can file a ticket without holding an admin credential.
- They are served by the Shipeasy **edge worker** (`api.shipeasy.ai`), not the
  admin API host. The generated client already routes them there.
- Every item is filed as `pending_approval`, parked out of the work queue until
  a human promotes it in the dashboard, and repeat submissions of the same title
  dedupe against the open ticket already tracking them (HTTP 200 with
  `deduped: true` instead of a second ticket).
- Pass `tags` — a list of tag names — to label the ticket. Each name is matched
  case-insensitively against the project's existing tags and reuses that tag
  (`ios` files under an existing `iOS`); a name with no match creates a new one.
  Up to 50 names of at most 64 characters each.

The project is the key's own project — there is no project id to pass and no way
to file into someone else's queue. The project must have public ticket creation
enabled.

```php
use Shipeasy\Admin\Generated\Model\CreatePublicBugRequest;
use Shipeasy\Admin\Generated\Model\CreatePublicFeatureRequestRequest;

$filed = $admin->ops()->createPublicBug(new CreatePublicBugRequest([
    'title' => 'Checkout 500s on Safari',
    'steps_to_reproduce' => 'Open the cart on iOS Safari and tap the price row.',
    'actual_result' => 'The primary CTA overlaps the price.',
    'priority' => 'high',
    'tags' => ['ios', 'checkout'],
]));
echo $filed->getNumber();

$admin->ops()->createPublicFeatureRequest(new CreatePublicFeatureRequestRequest([
    'title' => 'Dark mode',
    'use_case' => 'Reduce eye strain at night',
]));
```

### Attach the reporter's identity

Pass `user` — the same array you hand the SDK to evaluate flags for this
person — and Shipeasy evaluates every flag, kill switch and running experiment
for them at filing time. The ticket then shows what the reporter had on (flags
that were on, engaged kill switches, experiment arms) in the dashboard, and the
agent working it gets the same list. Omit it to file without one.

```php
$admin->ops()->createPublicBug(new CreatePublicBugRequest([
    'title' => 'Checkout spinner never stops',
    'user' => ['user_id' => $user->id, 'email' => $user->email, 'plan' => $user->plan],
]));
```

## List the ops queue

``listOpsItems`` reads the project's tickets (bugs, feature requests, errors, alerts) in
work order — highest priority first, oldest first within a priority. Filters
combine with AND and are applied before paging; walk the queue with `limit` and
`offset`.

- `reporter` — only items filed by one email (case-insensitive, exact). Items with
  no reporter never match, and while it is set `status=all` also returns
  `pending_approval` items, so a reporter sees the ticket they just filed.
- `closed_since` — items still being worked are always returned; `resolved` and
  `wont_fix` items only when they closed at or after this time.
- Every item carries `closed_at` (ISO-8601, null while open), so you can tell when
  a ticket closed without reading `updated_at`.

```php
$items = $admin->ops()->listOpsItems(
    status: 'all',
    reporter: 'jane@clinic.com',
    closed_since: new DateTime('2026-10-27T00:00:00Z'),
    limit: 50,
);
foreach ($items as $i) {
    echo '#', $i->getNumber(), ' ', $i->getStatus(), ' ', $i->getTitle(),
        ' (closed: ', var_export($i->getClosedAt(), true), ")\n";
}
```

## Toggle a kill switch

`toggleKillswitch` reads the current value and publishes its opposite in one
call. Every body field is optional, so it widens from "flip it" to "set exactly
this, on this environment". The body is the **third** argument (after the
optional `$x_project_id`) because the spec marks it optional:

```php
use Shipeasy\Admin\Generated\Model\ToggleKillswitchRequest;

$ks = 'payments.checkout'; // id (ksw_…) or name

// Flip the kill switch itself on prod — no body at all.
$admin->killswitch()->toggleKillswitch($ks);

// Flip one nested sub-switch on prod (created off→on if it doesn't exist yet).
$admin->killswitch()->toggleKillswitch($ks, null, new ToggleKillswitchRequest([
    'switch_key' => 'eu_region',
]));

// Set it idempotently — a retry can't undo the first call.
$admin->killswitch()->toggleKillswitch($ks, null, new ToggleKillswitchRequest([
    'switch_key' => 'eu_region',
    'value' => true,
]));

// …on a chosen environment.
$result = $admin->killswitch()->toggleKillswitch($ks, null, new ToggleKillswitchRequest([
    'switch_key' => 'eu_region',
    'value' => true,
    'env' => 'staging',
]));
printf("%s -> %s\n", var_export($result->getPrevious(), true), var_export($result->getValue(), true));
```

Omitting `value` (or passing `null`) means **flip**; passing an explicit
`true`/`false` means **set**. Omitting `env` targets `prod`.

## Manage a flag's whitelist

A gate's whitelist is the always-first allowlist that admits named identities
before any targeting rule or percentage rollout runs — the same list the
dashboard's Whitelist block edits.

```php
use Shipeasy\Admin\Generated\Model\AddToGateWhitelistRequest;
use Shipeasy\Admin\Generated\Model\RemoveFromGateWhitelistRequest;
use Shipeasy\Admin\Generated\Model\SetGateWhitelistRequest;

$gate = 'new_checkout'; // id (gate_…) or name

$wl = $admin->flags()->getGateWhitelist($gate);
print_r([$wl->getAttr(), $wl->getEntries()]); // ['email', ['alice@acme.dev']]

// Let one more person in (idempotent — already-listed entries are skipped).
$admin->flags()->addToGateWhitelist($gate, new AddToGateWhitelistRequest([
    'entries' => ['bob@acme.dev'],
]));

// Revoke one.
$admin->flags()->removeFromGateWhitelist($gate, new RemoveFromGateWhitelistRequest([
    'entries' => ['bob@acme.dev'],
]));

// Pin an exact list, re-key onto user ids, or clear it entirely.
$admin->flags()->setGateWhitelist($gate, new SetGateWhitelistRequest([
    'attr' => 'user_id',
    'entries' => ['usr_123'],
]));
$admin->flags()->setGateWhitelist($gate, new SetGateWhitelistRequest(['entries' => []]));
```

`setGateWhitelist` is the only call that can switch `attr` or drop the block —
`'entries' => []` removes the whitelist from the gate. Adding to a whitelist
keyed on the other attribute is rejected with a 409 rather than silently
re-keying everyone already listed.

## Resource groups

Each resource group is a lazily-constructed accessor: `flags()`, `killswitch()`,
`ops()`. Those three are the whole surface.

The exact method names, request models, and response shapes come straight from
the spec — explore them under `Shipeasy\Admin\Generated`.

## Escape hatch

`$admin->configuration()` exposes the underlying generated `Configuration` for
advanced use (custom headers, retries, a shared Guzzle client).

## Regenerating

The generated code lives under `src/Shipeasy/Admin/Generated/` and is committed.
`admin/openapi.json` is **not** the full Shipeasy spec — it is the dedicated
server-SDK contract, hand-authored in the monorepo as
`marketplace/openapi/spec/openapi-sdk.yaml` and bundled to `openapi-sdk.json`.
Do not hand-edit it, and do not replace it with the full `openapi.json`: that is
what bloats the generated client back to megabytes.

From the monorepo, re-vendor and regenerate in one step (only the generated
namespace is rewritten, never the `AdminClient` shim):

```bash
pnpm sdk:spec:regen sdk-php
```

A monorepo pre-commit hook blocks any commit that changes the admin spec while
this vendored copy is stale, so the two cannot silently drift.

The generator version is pinned in `openapitools.json`.
