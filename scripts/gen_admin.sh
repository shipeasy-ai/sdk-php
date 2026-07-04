#!/usr/bin/env bash
#
# Regenerate the OPTIONAL Admin API client (namespace `Shipeasy\Admin\Generated`)
# from the vendored OpenAPI spec. The generated client is a raw, 1:1 projection of
# `admin/openapi.json` (id-based, basis-points, snake_case) — no name->id or
# percent->bp ergonomics. The hand-written `src/Shipeasy/Admin/AdminClient.php`
# wrapper (the `AdminClient` entry point) sits on top and is NEVER touched by this
# script: only `src/Shipeasy/Admin/Generated/` is replaced.
#
# Usage:
#   1. Refresh the vendored spec when the contract changes:
#        cp <monorepo>/marketplace/openapi/openapi.json admin/openapi.json
#   2. Regenerate:
#        bash scripts/gen_admin.sh
#   3. Commit `admin/openapi.json` + `src/Shipeasy/Admin/Generated/`.
#
# Requires Java (for openapi-generator) and npx. The generator version is pinned
# in `openapitools.json`.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SPEC="admin/openapi.json"
DEST="src/Shipeasy/Admin/Generated"
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT

if [[ ! -f "$SPEC" ]]; then
  echo "error: missing vendored spec at $SPEC — copy it from the monorepo's marketplace/openapi/openapi.json" >&2
  exit 1
fi

# OpenAPI version-compat shim. The pinned openapi-generator (openapitools.json)
# bundles a swagger-parser that cannot parse OpenAPI >= 3.2 (it NPEs before
# codegen). The canonical admin spec is emitted as 3.2.x, but its *content* is
# 3.1-compatible — only the version label is ahead of the parser. Pin the label
# down to 3.1.0 (byte-preserving: only the version token changes) so the vendored
# spec is consumable. Harmless no-op when the spec is already <= 3.1.
perl -0pi -e 's/("openapi"\s*:\s*")3\.[2-9]\.\d+(")/${1}3.1.0${2}/' "$SPEC"

echo "Generating Shipeasy\\Admin\\Generated from $SPEC ..."
# srcBasePath='' makes the generator emit sources directly under the output dir
# (no extra lib/ level), namespaced by invokerPackage. We then lift the
# Shipeasy/Admin/Generated subtree into src/.
# --skip-validate-spec: the leniently-parsed 3.2-labelled spec trips the strict
# validator (spurious "unexpected"/"missing" errors); the codegen model builder
# handles the 3.1-expressible surface correctly, so skip validation.
npx --yes @openapitools/openapi-generator-cli generate \
  -i "$SPEC" \
  -g php \
  --skip-validate-spec \
  --additional-properties='invokerPackage=Shipeasy\\Admin\\Generated,srcBasePath=,packageName=shipeasy-admin' \
  -o "$BUILD" >/dev/null

# With srcBasePath='' the generator emits the PHP lib sources flat at the build
# root (Api/, Model/, Configuration.php, ApiException.php, ObjectSerializer.php,
# HeaderSelector.php, …) alongside docs/, test/, composer.json and README we do
# NOT want. Lift only the source dirs + top-level *.php into DEST.
if [[ ! -d "$BUILD/Api" || ! -d "$BUILD/Model" ]]; then
  echo "error: generator did not produce Api/ + Model/ under $BUILD" >&2
  find "$BUILD" -maxdepth 2 -type d >&2
  exit 1
fi

# Replace ONLY the generated subtree. The hand-written AdminClient.php and the
# rest of src/Shipeasy/ are left intact.
rm -rf "$DEST"
mkdir -p "$DEST"
cp -R "$BUILD/Api" "$BUILD/Model" "$DEST/"
cp "$BUILD"/*.php "$DEST/" 2>/dev/null || true

echo "Wrote $(find "$DEST" -name '*.php' | wc -l | tr -d ' ') PHP files to $DEST"
echo "Done. Review the diff and commit admin/openapi.json + $DEST."
