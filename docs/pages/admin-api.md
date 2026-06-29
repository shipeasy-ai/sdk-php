# Admin API client (optional) — `Shipeasy\Admin`

The base SDK *evaluates* flags, configs, and experiments (`Shipeasy\configure()`
+ `new Shipeasy\Client($user)`). The **Admin API client** is a separate, optional
surface for *administering* those resources from server code — creating gates,
starting experiments, managing configs, kill switches, universes, metrics,
events, and more.

It needs `guzzlehttp/guzzle`, which the base SDK does **not** require. Opt in by
installing it:

```bash
composer require guzzlehttp/guzzle
```

The client is **generated from the Shipeasy OpenAPI spec**, so it is a raw, 1:1
projection of the REST API: id-based, basis-points, snake_case. It does *not* add
the name→id resolution or percent→basis-point conveniences of the Shipeasy
CLI/MCP — reach for those tools when you want the ergonomic surface, and for this
client when you want a typed, programmatic mirror of the API.

## Authenticate and scope

Mint an **admin** SDK key (`sdk_admin_…`) and scope every call to a project.

```php
use Shipeasy\Admin\AdminClient;

$admin = new AdminClient(
    getenv('SHIPEASY_ADMIN_KEY'),     // Authorization: Bearer <key>
    getenv('SHIPEASY_PROJECT_ID'),    // X-Project-Id on every request
    // 'http://localhost:3000',       // host; defaults to https://shipeasy.ai
);

$flags = $admin->flags()->listGates();
```

`projectId` is sent as the `X-Project-Id` header on every request. Individual
operations also accept an explicit `$x_project_id` argument to override per call.

## Resource groups

Each resource group is a method returning the matching generated api whose
methods map 1:1 to the OpenAPI operations:

```php
$admin->flags()->createGate($request);
$admin->experiments()->createExperiment($request);
```

Available groups: `flags()`, `configs()`, `killswitch()`, `experiments()`,
`universes()`, `attributes()`, `metrics()`, `events()`, `ops()`, `alerts()`,
`projects()`, `profiles()`, `keys()`, `drafts()`, `errors()`, `connectors()`,
`apiKeys()`. The exact method names, request models, and response shapes come
straight from the spec — explore them under `Shipeasy\Admin\Generated\Model` or
with your editor's autocomplete.

## Regenerating

The generated code lives under `src/Shipeasy/Admin/Generated/` and is committed.
When the API contract changes, refresh the vendored spec and regenerate — only
the generated subtree is rewritten, never the `AdminClient` shim:

```bash
cp <monorepo>/packages/openapi/openapi.json admin/openapi.json
bash scripts/gen_admin.sh
```

The generator version is pinned in `openapitools.json`.
