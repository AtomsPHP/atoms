# PHP 8.3 Worker interpreter

The shipping scaffold selects WordPress Playground 3.1.48. This directory builds the Atoms candidate for the same PHP 8.3.32 web SAPI. Both use Wrangler's precompiled Wasm import, the universal loader, MEMFS and the Worker’s existing Asyncify dispatcher. Application tables and migration history live in the Durable Object’s SQLite storage.

## OpenSSL compatibility

Playground exposes SSLv23 RSA padding through `OPENSSL_SSLV23_PADDING`. OpenSSL removed this mode in 3.0. Existing PHP code that references that constant fails on the candidate. A numeric mode of 2 also fails for previously working public encryption and private decryption calls. The OpenSSL 3 legacy provider restores the older symmetric ciphers and digests but has no implementation of this RSA mode. Full application compatibility remains a promotion blocker. [Upstream removal decision](https://github.com/openssl/openssl/issues/14283)

## Rebuild and identify the candidate

From this directory, use Python 3, Node, Git, tar, patch, Docker with Buildx, and npm. Choose an absolute work directory outside the repository on a filesystem with room for two independent compiler builds. Set `version` to the exact npm version intended for this candidate before packing.

```sh
work=/absolute/path/to/build-work
python3 -B build.py fetch "$work/inputs"
python3 -B build.py verify "$work/inputs"
python3 -B build.py build "$work/inputs" "$work/first"
python3 -B build.py build "$work/inputs" "$work/second"
(cd "$work/first" && wc -c php_8_3.* && sha256sum php_8_3.*)
(cd "$work/second" && wc -c php_8_3.* && sha256sum php_8_3.*)
python3 -B source-bundle.py "$work/inputs" "$work/first"
node pack.mjs "$work/first" "$work/second/build-metadata.json" "$version" "$work/packed"
```

The compiler and library locks give the Linux/amd64 image digest, PHP and Playground commits, source URLs and digests. The build driver prepares a new build context for each invocation, verifies the inputs, disables the Docker build cache and runs compiler steps without network access. The output directories must be empty. The Worker target patch selects the pinned Playground web SAPI and Asyncify instrumentation. The final glue imports its sibling Wasm and exposes `Module.Asyncify` for synchronous transaction re-entry.

The packer requires identical build records from both clean builds and verifies both files against those SHA-256 values. A maintainer can download the second build record and sizes from the independent runner. It emits the exact npm tarball and a separate `interpreter.json` for the scaffold. Review and retain the input locks, build commit, both output hash pairs and sizes, npm integrity, `components.json`, notices and corresponding source for that tarball. The reproducibility workflow records the identities from separate clean runners. Keep the compiler outputs, archives and compiler caches outside Git.

From an extracted `corresponding-source.tar.gz`, change into `php-wasm-build/`, run `python3 -B build.py fetch ../inputs`, then `python3 -B build.py verify ../inputs` and `python3 -B build.py build ../inputs ../rebuilt`. The archive supplies the PHP, Playground and library sources. The fetch step supplies the locked builder image and host tools. Run Worker qualification and coordinated release commands from the matching Atoms checkout.

## Install and qualify

1. Record the shipping control from a clean `npm ci` in `cloudflare/worker`. Run `node scripts/gen-runtime-functions.mjs --check` and save `node scripts/gen-runtime-functions.mjs --inspect` to a JSON file. The latter records the actual loaded APIs, constants, INI settings, native SQLite, wide integers, mbstring and OpenSSL behavior. The 0.10.0 CLI advertised sockets, pcntl, posix and uv despite their absence in the guest. The current resolver uses the measured extension map from #203.
2. Run `node qualification-scaffold.mjs "$work/packed/interpreter.json" "$work/packed/<candidate>.tgz" "$work/scaffold"`. This builds a review-only scaffold whose npm lock resolves the exact local tarball. Keep that tarball at the same absolute path during the run. Use `npm exec --yes --package="$work/scaffold/<scaffold>.tgz" -- atoms-runtime-cloudflare init atoms-worker` in a clean application checkout. Repeat with `upgrade atoms-worker` in an existing checkout. Compare the user’s `wrangler.jsonc` before and after upgrade.
3. Run `npm ci` in the scaffold. Compare the selected staged files with both reviewed hashes. The scaffold owns the pin. Its preparation script checks the installed version, lengths and digests before selecting either file. On a mismatch, restore the intended release pin, package manifest and lock together and repeat `npm ci`. Build the unmodified app with its normal `vendor/bin/atoms build`, start `vendor/bin/atoms dev --env staging` and exercise a real call. Package the staged Worker with the scaffold’s `node_modules/.bin/wrangler deploy --dry-run --outdir <local-output>`.
4. After `npm ci` in the installed scaffold, run `python3 conformance.py /absolute/path/to/atoms-worker "$work/conformance"` here with PyYAML, Node and PHP available. The runner stages the installed interpreter in temporary storage, bundles the repository fixture with the scaffold’s PHP runtime, and saves the suite and Worker logs for each CI posture in a new results directory. It uses the Worker job’s check selections, per-posture capability flags and the suite’s real eviction waits. Compare the full guest report and the CLI inventory to the control. Review removed or changed capabilities individually, including crypto algorithms and native PDO SQLite. Run the complete framework and scaffold checks.
5. Repeat task #75 reply #86 on demo commit `baadd7dc3ef0c6f7e32321cc5d00b5627bb63cee`. Use its original scripts and client, the normal demo bundle, the same host and the same Wrangler. Time 20 evicted rooms after the 30-second idle and 20 immediate returns separately. Verify a construction-counter increase for each evicted room and verify both seats, a move and reconnects. Save raw milliseconds, median and nearest-rank p95 for each cohort and the same-host Playground control. Report initial creation plus second-player join separately. Investigate timing differences before promotion.
6. Rehearse recovery on the same local room and bundle. Write on Playground, record the DO ID, migration history, seats and game state, then stop the Worker. Restart the candidate using the same namespace, persistence directory and bindings. Reconnect and mutate. Restore the recorded Playground build and pins, reconnect and read and write again. Record each state's contents and migration history.

## Update and release

For a PHP, extension or toolchain change, update the source locks, review upstream source changes and refresh the Worker target patch. Audit the Asyncify selection, linked extension features and license texts. Rebuild twice, compare final bytes, regenerate corresponding source and qualify the exact new tarball. A failed or missing gate blocks promotion.

For an approved promotion, configure the artifact package's trusted publisher for AtomsPHP/atoms, its protected release workflow and the release environment. In the approved publishing job, grant `id-token: write`, verify the retained tarball against the recorded npm integrity, then run `npm publish "$tarball" --access public --provenance`. Use a new immutable npm version for any changed bytes. Retain the prior tarball and source. The current release workflow publishes the scaffold; enabling artifact publication is a separate release change. [Repository release procedure](https://github.com/AtomsPHP/atoms/blob/main/release/README.md) · [npm trusted publishing](https://docs.npmjs.com/trusted-publishers/)

In the coordinated scaffold release, install the exact published npm version and check the registry lock integrity against the qualified tarball. Commit the reviewed interpreter pin, dependency and lock, synchronize `TargetSyntax::PHP_VERSION` and `VendorStage::WORKER_PHP_VERSION` under `packages/cli/src/Build/`, then run `node scripts/gen-runtime-functions.mjs` in the Worker checkout with the promoted interpreter staged. For a PHP patch update, render the PDO document with `node scripts/gen-pdo-matrix.mjs > ../docs/pdo-compatibility.md` from check 28's result and review the changes. The scaffold packer regenerates the ownership stamp. Use `php scripts/release/release.php set <version> ready` from the repository root to prepare the coordinated release manifest. Customers upgrade the scaffold with its normal installer and run `npm ci`.

## Recover a deployed Worker

Before an approved deployment, save the known-good Worker version ID from `vendor/bin/atoms status --env <env>`, the application bundle hash, DO binding/class, CLI/scaffold versions, interpreter pin and npm lock. A secret rotation may create another Worker version between code deployments.

Use `vendor/bin/atoms rollback <known-good-version-id> --env <env>` to restore the recorded code. Check `atoms status`, reconnect both sessions to the original room and verify the last committed mutation and a subsequent write. Worker rollback leaves the bound Durable Object data in place. The older application must still read that schema. Restore the known-good scaffold/CLI and matching package/lock before the next deployment. [Cloudflare rollback behavior and limitations](https://developers.cloudflare.com/workers/versions-and-deployments/rollbacks/)
