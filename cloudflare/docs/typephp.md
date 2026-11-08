# Experimental TypePHP WebAssembly backend

`--compiler typephp` selects [TypePHP](https://github.com/swoole/typephp) when
staging a Worker bundle. The default is `php-wasm`. `atoms build` produces the
same portable archive and manifest for either backend. Select the compiler
when running `dev`, `deploy`, or the Worker's bundle translator.

```sh
atoms build
atoms dev --env staging --compiler typephp
atoms deploy --env production --compiler typephp
```

Laravel's `php artisan atoms:dev` and `atoms:deploy` accept the same option.
Symfony's deploy wrapper forwards it after `--`. `dev --no-build` uses the
already staged backend and rejects an explicit `--compiler` option. To return
to the default backend, stage again with
`--compiler php-wasm` or omit the option on a command that builds.

## Scope and measured limits

This backend is experimental and uses the PHP 8.5 ABI. The default
interpreter uses PHP 8.3. TypePHP compiles the Atoms runtime, core declarations,
and each primary Atom source file ahead of time. Supporting PHP classes,
migrations, and Composer function files remain guest files that PHP loads at
runtime. The backend combines AOT compilation with a PHP interpreter.

Each primary Atom must directly extend `Atoms\Atom` and must not use traits.
The source adapter rejects unsupported primary inheritance before compilation.
Compiled files may contain only declarations at global scope. Put executable
statements inside functions or methods. TypePHP enforces this restriction
during compilation, including for top-level includes and initialization code
in a primary Atom file. Other TypePHP restrictions can also fail compilation
with `ATOMS-E113`. The build does not execute customer PHP. The source adapter
tokenizes files, then the compiler emits and links code without its run option.

The host uses WASI command components and JSPI suspension through Jco. Standard
streams carry the existing Atoms host-call protocol. SQL uses Durable Object
storage. The selected PHPX SDK provides the PHP 8.5 runtime and its native
extensions. Its extension set differs from the default Playground interpreter.
The conformance results below record the tested behavior and known failures.

The tested installation and source cache require Linux. Install the tools
locally before building. `atoms build`, staging, and deployment do not download
a compiler, run an installer, or repair an SDK.

## Current conformance differences

The unmodified conformance suite ran against the complete compiled fixture
with Wrangler 4.118.0, compatibility date 2026-08-01, and the pinned toolchain
below. The full run with bearer authentication disabled completed with 31
checks passing and 7 failing. The TypePHP backend does not meet the complete
default runtime contract. The PHP 8.3 acceptance gate and committed PDO
compatibility matrix remain unchanged.

| Checks | Observed difference |
|---|---|
| 9 | Returning an array nested 600 levels deep traps with an out-of-bounds WASM memory error and discards the residency, instead of returning `atom_exception`. Other int64 cases passed. |
| 14, 17, 20 | Compiled transaction closures that declare no parameters reject the database argument with `ArgumentCountError`. This prevents those callback, job, and broadcast scenarios. |
| 26 | PHP 8.5 changes PDO fetch constants and the ABI differs from the pinned PHP 8.3 reflection contract. |
| 28 | Native PDO quoting, NUL handling, and statement attribute behavior differ. Compiled PDO quote methods also reject weak boolean and integer coercions that the default runtime accepts. |
| 30 | The measured PHP 8.5 PDO report differs from the committed PHP 8.3 report. Keep that report unchanged. |

TypePHP documents extra-argument rejection as an
[intentional language rule](https://github.com/swoole/typephp/blob/5ba9376642d8dd75efcd002b41b09a9d6bcca3e2/docs/en/INCOMPATIBLE_PHP_FEATURES.md#calls-and-references).
Declare the database parameter on transaction callbacks when using this backend,
including when the callback does not use it. This does not resolve the other
compatibility differences above. The SDK also emits tentative-return-type
notices for compiled PDO overrides despite their `ReturnTypeWillChange`
attributes. These notices appear in guest output logs.

Activation, isolation, migrations, explicit transaction commit and rollback,
residency recovery, signed callbacks, deadlines, nontransactional job delivery,
WebSocket echo and hibernation, timers, and SQL result caps passed their checks.

## Pinned local toolchain

These are the pinned inputs for the tested installation. Preflight checks
tool versions, the target ABI, and SDK contents as described below. Pin the
compiler source commit and Composer lock during installation.

| Component | Pin |
|---|---|
| TypePHP | `0.9.5`, commit `5ba9376642d8dd75efcd002b41b09a9d6bcca3e2` |
| Host PHP for source compiler | Measured with `8.5.9` NTS; upstream requires PHP `>=8.4 <8.6` |
| PHPX | `2.9.4`, commit `f6a2555e14694e8888d2f40ff2838ffb22bc04f2` |
| PHPX target runtime | PHP `8.5.9`, `wasm32-wasip2`, SDK ABI `typephp-wasip2-sdk-abi-v4` |
| WASI SDK | `33.0`, LLVM `22.1.0`, target `wasm32-unknown-wasip2` |
| Component linker | `wasm-component-ld 0.5.22`, supplied by WASI SDK 33 |
| Wasmtime | `47.0.0` |
| Jco | `@bytecodealliance/jco@1.41.0`, installed separately as a local build tool |
| Host WASI shim | `@bytecodealliance/preview2-shim@0.31.0`, pinned in the Worker lockfile |

Command components do not need `wit-bindgen`. Keep the compiler source,
Composer lock, original downloads, and SDK repair record with the toolchain.
The source compiler used these dependency versions:

```text
ajaxray/ansikit             0.3.1
league/climate             3.12.0
marcj/topsort               2.0.0
nikic/php-parser           5.9.0
psr/log                    3.0.2
seld/cli-prompt            1.0.4
swoole/phpx                2.9.4
symfony/polyfill-ctype     1.37.0
symfony/polyfill-mbstring  1.43.3
symfony/var-dumper         8.1.10
symfony/yaml               8.1.11
```

With the selected PHP and Composer already installed, check out the exact
source commit and install the pinned dependencies:

```sh
git clone --no-checkout https://github.com/swoole/typephp.git /opt/atoms-typephp/typephp
git -C /opt/atoms-typephp/typephp checkout --detach 5ba9376642d8dd75efcd002b41b09a9d6bcca3e2
cd /opt/atoms-typephp/typephp
composer update --no-dev --no-scripts --no-plugins \
  --with ajaxray/ansikit:0.3.1 --with league/climate:3.12.0 \
  --with marcj/topsort:2.0.0 --with nikic/php-parser:5.9.0 \
  --with psr/log:3.0.2 --with seld/cli-prompt:1.0.4 \
  --with swoole/phpx:2.9.4 --with symfony/polyfill-ctype:1.37.0 \
  --with symfony/polyfill-mbstring:1.43.3 \
  --with symfony/var-dumper:8.1.10 --with symfony/yaml:8.1.11
```

Keep the resulting lock file. For subsequent installations, use
`composer install --no-dev --no-scripts --no-plugins`. This setup happens
outside an application build. The source compiler entry point is
`php /path/to/typephp/bin/tpc.php`. Make it available as a local `tpc` executable
or a shell wrapper that forwards `"$@"` to that entry point. Install Jco in a
separate tool directory with `npm install --save-exact
@bytecodealliance/jco@1.41.0`. Keep its package lock and add its
`node_modules/.bin` to `PATH`. Do not use `npx` during staging.

The tested Linux x86-64 release archives have these SHA-256 digests. They match
the upstream GitHub release asset metadata:

| Archive | SHA-256 |
|---|---|
| [wasi-sdk-33.0-x86_64-linux.tar.gz](https://github.com/WebAssembly/wasi-sdk/releases/tag/wasi-sdk-33) | `0ba8b5bfaeb2adf3f29bab5841d76cf5318ab8e1642ea195f88baba1abd47bce` |
| [wasmtime-v47.0.0-x86_64-linux.tar.xz](https://github.com/bytecodealliance/wasmtime/releases/tag/v47.0.0) | `abe28fca78aac37db4487760850222042bb753d1eb80830cb032fb1007cc25cf` |
| [phpx-wasi-sdk_v2.9.4_php8.5.9_wasm32-wasip2.tar.gz](https://github.com/swoole/phpx/releases/tag/v2.9.4) | `a0358df19d725a703498c22da32066b973095a0c9e1e613dc0d40b6be06c843d` |

Verify these digests before extraction. Verify the source and digest separately
for any other platform's archive.

## Preparing the incomplete upstream SDK

The PHPX 2.9.4 release archive has the correct ABI marker but omits headers and
object files that TypePHP 0.9.5 needs. That archive alone fails preflight. Run
[`prepare-typephp-sdk.py`](../worker/scripts/prepare-typephp-sdk.py) manually
to build a new SDK directory from verified local inputs. The script requires
Python 3.12 or later.

```sh
python3 cloudflare/worker/scripts/prepare-typephp-sdk.py \
  --phpx-source /opt/atoms-typephp/typephp/vendor/swoole/phpx \
  --release-archive /opt/atoms-typephp/downloads/phpx-wasi-sdk_v2.9.4_php8.5.9_wasm32-wasip2.tar.gz \
  --wasi-sdk /opt/atoms-typephp/wasi-sdk-33.0-x86_64-linux \
  --output /opt/atoms-typephp/typephp/vendor/swoole/phpx/wasm/wasm32-wasip2
```

The output must not already exist. The script verifies the release archive's
digest, the exact PHPX source subtree used by the repair, the compiler version,
and target. It copies five missing headers and compiles these existing upstream
implementations into `libphpx.a`:

```text
src/typephp/typephp_attribute.cc
src/typephp/typephp_call.cc
src/misc/typephp_runtime.cc
src/typephp/typephp_property.cc
src/typephp/typephp_trait.cc
```

It does not modify PHPX source or add stubs. Do not add
`typephp_opcode_table.cc`. TypePHP generates those functions itself, and linking
that unit causes duplicate definitions. The script retains
the release's licence files and writes `atoms-sdk-repair.json` with input
digests, tool versions, changed files, and the resulting library digest. In the
measured build, the repaired `libphpx.a` SHA-256 is
`2f8f27e37c1bef08de89149db84e13f03405448f9292a1f9066e7db219aebb26`.
The original `FILES.SHA256` covers only the unmodified release files.

TypePHP 0.9.5 also checks for an Embed SAPI library at its detected host PHP
prefix before its WASI build. The test installation used a private PHP prefix
without `include/` or `lib/`. Linking those paths to the prepared WASI SDK's
`include/` and `lib/` satisfied the presence check. The compiler uses the WASI
target libraries for the build. Create these links only inside a dedicated
toolchain prefix. Never replace a system PHP installation's directories. You
can also use a host PHP installation with its Embed library. If the check
fails, fix the host installation before building. Do not enable TypePHP's
interactive `php-builder` from a bundle build.

## Selecting and checking the installation

Add the local tools to `PATH`. The runner checks their versions before compiling:

```sh
export PHPX_HOME=/opt/atoms-typephp/typephp/vendor/swoole/phpx
export ATOMS_TYPEPHP_COMPILER=/opt/atoms-typephp/bin/tpc
export ATOMS_TYPEPHP_PHP=/opt/atoms-typephp/bin/php
export PATH=/opt/atoms-typephp/bin:/opt/atoms-typephp/wasi-sdk-33.0-x86_64-linux/bin:/opt/atoms-typephp/wasmtime-v47.0.0-x86_64-linux:/opt/atoms-typephp/jco/node_modules/.bin:$PATH
```

`ATOMS_TYPEPHP_PHP` selects the tokenizer's PHP executable. It does not change
the target ABI. `PHPX_HOME` must name the PHPX package root containing
`wasm/wasm32-wasip2`. `ATOMS_TYPEPHP_COMPILER` defaults to `tpc`. The runner finds
`jco` on `PATH`. The source compiler also needs its Composer dependencies
installed and `php` on `PATH`. Run this read-only preflight from the repository
root:

```sh
node --input-type=module -e "import { preflightTypePhp } from './cloudflare/worker/scripts/lib/typephp-compiler.mjs'; preflightTypePhp();"
jco --version
```

Preflight checks SDK headers, seven static archives, and defined PHPX symbols,
including the missing property and runtime entry points. The translation stage
then checks Jco's exact version. Missing tools or unsupported source fail with
`ATOMS-E113` when staging through the CLI.

To test the installation separately, create `hello.php` containing
`<?php function main(): void { echo "TypePHP WASI smoke\n"; }` and this
`project.yml` in a scratch directory:

```yaml
name: atoms-typephp-smoke
mode: bin
wasm: component
build-dir: build
output: hello.wasm
sources:
  - hello.php
```

Run `tpc project.yml` to compile it, then run `wasmtime -S http hello.wasm`.
The PHP runtime imports WASI HTTP interfaces, so even this hello component
requires the `http` linker capability. Normal Atoms builds do not execute the
compiled component.

You can also run the Worker translator from the Worker directory:

```sh
node scripts/bundle-from-cli.mjs /path/to/bundle.tar.gz /path/to/manifest.json src/bundle.generated.js --compiler typephp
```

It emits `src/bundle.generated.js`, `src/runtime.generated.js`, and referenced
component/Jco artifacts under `src/.typephp/<source-hash>/`. Keep the generated
files together. Jco emits several core modules, including conditional host
import modules. The runtime needs all of them. Do not commit generated WASM.

## Determinism and local state

TypePHP embeds physical source paths in generated strings. Identical source
under different temporary source roots produced different WASM bytes in the
test. Atoms stages sources at
`/tmp/atoms-typephp/<source-hash>/sources/`, sets `SOURCE_DATE_EPOCH=0`, and
preserves guest `__FILE__`/`__DIR__` values during source preparation. Two clean
builds at the same canonical source root produced identical component and Jco
files with the same toolchain. Different compiler or SDK versions may produce
different binaries.

The source hash covers source contents, compiler and target versions, SDK
static-library and header hashes, and the Jco version. The user running the
build must own the source cache with mode `0700`. The runner rejects symlinks
and unsafe source paths. Exclusive source and output locks prevent competing
writes. After a crash, confirm the build has stopped before removing its stale
lock. The runner completes compilation and translation before replacing a
staged bundle. It records build provenance in each artifact directory's
`typephp-build.json`.

See [third-party notices](../THIRD_PARTY_NOTICES.md#optional-typephp-backend)
for compiler and runtime component provenance.
