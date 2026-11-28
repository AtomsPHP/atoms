# Private PHP 8.3 embed build

This recipe builds PHP 8.3.32 with Emscripten 4.0.19 and links a Rust static library directly into the embed SAPI. The ordinary Worker installer still uses the stack at `321a2906`. The guest runs one PHP script per Wasm instance. Its fixtures exercise the static Zend module, the wasm SAPI, 64-bit integer exchange and the Zend heap inside local workerd. Fiber calls stop the private probe at entry.

Use an x86-64 Linux host with Docker, Python 3.11 or later, dpkg-deb, tar and the libclang build identified in `tools.lock.json`. The fetch step verifies the input archives. The build step needs the pinned Emscripten image in the local Docker image store. It installs the pinned Rust/Cargo tools and the Emscripten Rust target under the new output directory. PHP compilation and linking run offline.

```sh
cd cloudflare/php-wasm-build/rust
python3 build.py fetch /tmp/atoms-php-inputs
python3 build.py build /tmp/atoms-php-inputs /tmp/atoms-php-build --jobs 4
python3 test.py /tmp/atoms-php-build
python3 test.py /tmp/atoms-php-build --turns
python3 test.py /tmp/atoms-php-build --inventory /tmp/atoms-php-inventory.json
```

Pass `--workerd /path/to/workerd` to use the binary from another installation of the locked Worker dependencies. The test gives the Durable Object a fresh on-disk SQLite store on each run.

The embed build enables 64-bit Zend integers on wasm32. The `timelib-long64.patch` build option selects the matching timestamp width in PHP’s bundled date library. Without it, `gmmktime(3, 14, 8, 1, 19, 2038)` warns and returns false. Stack #225 returns 2147483648. The default ABI probe checks both sides of the signed 32-bit limits in 1901 and 2038.

The Wasm module embeds the Worker's PHP configuration and message function in MEMFS. PHP 8.3 supplies OPcache as a Zend extension. The build archives its upstream object list and registers its extension entry during module startup. The Worker uses the file-cache settings from stack #225. On both builds PHP 8.3's OPcache reports an inactive cache under the `wasm` SAPI.

The build verifies the libraries in `libraries.lock.json` before compiling them offline. The PHP release probe runs in local workerd with native PDO SQLite. It exercises a signed 64-bit column value, mbstring regular expressions, the cURL protocols, stream and ZIP support, and OpenSSL digests and certificate fingerprints. A separate image fixture encodes and decodes the GD formats that PHP’s configure script cannot run across architectures. The Rust adapter supplies PHP 8.3's SHAKE output sizes to OpenSSL and loads the linked legacy provider.

The Emscripten stack has the same 1 MiB size as the control. Rust returns its actual bounds to Zend. The build compiles Zend’s stack-query file at `-O0` so its initialization code calls the platform adapter. At `-O1` the compiler folds the upstream wasm32 query into a constant failure, including with `-fno-inline`. The loader checks that the adapter ran before entering PHP.

The control’s process-title handlers come from the CLI SAPI. The embed registers the same upstream handlers under the standard extension. Both guests expose `proc_open` and return false for an unavailable executable. The control’s private DNS extension supplies warning-only functions and has no callers in the Atoms runtime or entries in its CLI function inventory.

The turn fixture boots the owned adapter over stock emcc glue. It uses the Worker's SQL bridge and transaction machine. Each failure check inserts and reads its marker on the callback stack. Subsequent HTTP requests check the SQLite rows, DO identity, durable construction count and PHP generation. The compile fatal comes from an included file. The memory-limit fatal occurs while PHP's fread allocates a Zend-owned reply string after the Rust callback has returned. Discarding the instance closes its wakes and skips user shutdown callbacks.

The growth case enlarges the actual transfer allocation for the tx.begin acknowledgement. It requires more than 64 MiB of observed growth and a detached pre-allocation heap view before PHP rewinds in the transaction. The tests also try a consumed wake and a wake belonging to a discarded PHP instance.
