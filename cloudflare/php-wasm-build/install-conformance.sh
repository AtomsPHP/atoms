#!/usr/bin/env bash
# Install the customer tarball, then add only CI's local harness and test config.
set -euo pipefail
tarball=$(realpath "$1")
target=$(realpath -m "$2")
series=$3
worker=$(cd "$(dirname "$0")/../worker" && pwd)
npm exec --yes --package="$tarball" -- atoms-runtime-cloudflare init "$target"
cd "$target"
npm ci --no-fund --no-audit
mkdir -p test ../docs
cp "$worker"/test/{conformance.mjs,required-capabilities.mjs,pdo-expected.json,http-conformance.mjs,http-worker.js} test/
cp "$worker"/scripts/{build-bundle.mjs,dev-with-callback.mjs,gen-pdo-matrix.mjs} scripts/
cp "$worker/wrangler.jsonc" .
cp "$worker/../docs/pdo-compatibility.md" ../docs/
node scripts/build-bundle.mjs "$worker/fixtures/counter" src/bundle.generated.js "$series"
