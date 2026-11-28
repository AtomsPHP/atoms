# Private PHP 8.3 embed build

This recipe builds PHP 8.3.32 with Emscripten 4.0.19 and links a Rust static library directly into the embed SAPI. The public Worker installer uses the PHP packages pinned on main. The frozen #228 build at `321a2906` supplies the comparison. The minimal guest runs one PHP script per Wasm instance. Its fixtures exercise the static Zend module, the wasm SAPI, 64-bit integer exchange and the Zend heap inside local workerd. Fiber calls stop the private probe at entry.

Use an x86-64 Linux host with Docker, Python 3.11 or later, dpkg-deb, tar and the shared-library dependencies of libclang identified in `tools.lock.json`. The fetch step verifies the input archives. The build step needs the pinned Emscripten image in the local Docker image store. It installs the pinned Rust/Cargo tools and the Emscripten Rust target under the new output directory. PHP compilation and linking run offline.

```sh
cd cloudflare/php-wasm-build/rust
python3 build.py fetch /tmp/atoms-php-inputs
python3 build.py build /tmp/atoms-php-inputs /tmp/atoms-php-build --jobs 4
python3 test.py /tmp/atoms-php-build
```

Pass `--workerd /path/to/workerd` to use the binary from another installation of the locked Worker dependencies. The test gives the Durable Object a fresh on-disk SQLite store on each run.

`test.py --timestamps` also checks the two UTC seconds around the signed 32-bit boundary in January 2038. The upstream date library currently selects 32-bit integers on wasm32. The following call returns false even though PHP reports 8-byte integers. The #228 interpreter returns 2147483648.

```php
gmmktime(3, 14, 8, 1, 19, 2038)
```

The pending timelib port decision and the measured calls are in Atoms Board task #289, reply #293.
