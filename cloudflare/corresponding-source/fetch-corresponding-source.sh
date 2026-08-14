#!/usr/bin/env bash
#
# Rebuild the corresponding-source archive for the php-wasm binary this Worker
# runs on, and verify it against SHA256SUMS.
#
# The binary is fetched from npm at install time. This script lets the pinned
# artifact's source be obtained and checked on demand and is groundwork for
# M5. See README.md.
#
# The verification is the point. A script that only downloads proves nothing:
# it is the byte-for-byte match against the recorded hashes that turns "here
# is some upstream source" into "here is the source this binary was built
# from". If the hashes do not match, this script fails loudly rather than
# leaving an unverified archive behind for someone to publish by mistake.
#
# Usage:  ./fetch-corresponding-source.sh [output-directory]
#
# The default output directory is ./out, which is not committed. Expect the
# fetch to take a few minutes and about 700 MB of scratch space; the archive
# alone is 506 MB uncompressed and 158 MiB gzipped.
#
# See README.md for what this archive does and does not cover — the short
# version is that it covers Playground's own contributions and the whole
# build recipe, but not the source of PHP itself or of the statically linked
# libraries, which the recipe fetches from third-party hosts at build time.

set -euo pipefail

readonly COMMIT='53d5c3030702e1edb3f82e7dbc308fcac27c3d0a'
readonly TREE='0db020c5bd51a4d32c97929a64191a7de6356a28'
readonly UPSTREAM='https://github.com/WordPress/wordpress-playground.git'
readonly PREFIX='wordpress-playground-53d5c30'

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
out="${1:-$here/out}"

for tool in git gzip sha256sum; do
    command -v "$tool" >/dev/null 2>&1 || {
        echo "error: $tool is required but not on PATH" >&2
        exit 1
    }
done

mkdir -p "$out"
work="$out/wordpress-playground"

# A shallow fetch of the single commit is enough: `git archive` depends only
# on that commit's tree, so history we do not fetch cannot change the result.
if [ ! -d "$work/.git" ]; then
    echo "==> fetching $COMMIT from $UPSTREAM (shallow)"
    git init --quiet "$work"
    git -C "$work" remote add origin "$UPSTREAM" 2>/dev/null || true
    git -C "$work" fetch --quiet --depth=1 origin "$COMMIT"
else
    echo "==> reusing existing checkout in $work"
    git -C "$work" cat-file -e "$COMMIT^{commit}" 2>/dev/null ||
        git -C "$work" fetch --quiet --depth=1 origin "$COMMIT"
fi

# The tree hash is the durable identity of this source. `git archive`'s tar
# framing is not contractually frozen across git releases, so if a future git
# ever produces different bytes, this check is the one that still means
# something.
actual_tree="$(git -C "$work" rev-parse "$COMMIT^{tree}")"
if [ "$actual_tree" != "$TREE" ]; then
    echo "error: tree hash mismatch for $COMMIT" >&2
    echo "  expected $TREE" >&2
    echo "  got      $actual_tree" >&2
    exit 1
fi
echo "==> tree hash matches: $TREE"

echo "==> writing $PREFIX.tar"
git -C "$work" archive --format=tar --prefix="$PREFIX/" "$COMMIT" \
    > "$out/$PREFIX.tar"

# `gzip -n` omits the original filename and mtime from the gzip header, which
# is what makes the compressed file reproducible rather than merely the tar.
echo "==> writing $PREFIX.tar.gz"
gzip -n -9 < "$out/$PREFIX.tar" > "$out/$PREFIX.tar.gz"

echo "==> verifying against SHA256SUMS"
( cd "$out" && sha256sum --check --strict "$here/SHA256SUMS" )

cat <<EOF

Verified. Both archives match the hashes recorded in SHA256SUMS.

  $out/$PREFIX.tar
  $out/$PREFIX.tar.gz

The .tar.gz is a reproducible local copy of the pinned upstream source. See
README.md for the scope and known gaps in the upstream build recipe.
EOF
