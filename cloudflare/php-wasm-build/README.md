# Building the Atoms PHP Wasm packages

Each package contains matching JavaScript and Wasm, the build record, component inventory, license notices and corresponding source. The three supported targets use the upstream PHP embed SAPI, an Atoms Rust bridge, Emscripten 4.0.19 and OpenSSL 3.5.9. The default guest is PHP 8.3.32.

| PHP series | PHP version | Package |
| --- | --- | --- |
| 8.3 | 8.3.32 | `@atomsphp/php-wasm-8-3` |
| 8.4 | 8.4.28 | `@atomsphp/php-wasm-8-4` |
| 8.5 | 8.5.13 | `@atomsphp/php-wasm-8-5` |

## Build and pack

Choose a series. Use Docker on Linux/amd64, Python 3.11 or newer, Node, npm, dpkg-deb, tar, patch and the host dependencies of the libclang package pinned in `rust/tools.lock.json`. Run the commands on a fresh builder. The fetch step checks the source inputs and obtains the locked compiler image. Compilation runs offline with a private compiler cache.

```sh
series=8.5
work=/absolute/path/to/work
python3 -B build.py fetch --php "$series" "$work/inputs"
python3 -B build.py verify --php "$series" "$work/inputs"
python3 -B build.py build --php "$series" "$work/inputs" "$work/output"
python3 -B source-bundle.py --php "$series" "$work/inputs" "$work/output"
```

Extract the corresponding source on a second builder. From the extracted `php-wasm-build` directory, run the same fetch and build against its sibling `inputs` directory. Fetch verifies the included sources and obtains the pinned compiler tools. Bring the second builder’s `build-metadata.json` to the first builder. Compare both output sizes and SHA-256 values. Pack the matching pair with the reviewed version.

```sh
version=$(jq -r .version "targets/$series/candidate.json")
cmp "$work/output/build-metadata.json" "$work/peer/build-metadata.json"
node pack.mjs "$work/output" "$work/peer/build-metadata.json" "$version" "$work/packed"
cmp "targets/$series/candidate.json" "$work/packed/interpreter.json"
```

The pin identifies the pair, the complete npm archive and its packed and unpacked sizes. Use a new npm version when any package contents change, including embedded source. Review the compiled pair, corresponding source, package integrity and boot inspection from the qualification workflow before accepting new pins. The build record in the package identifies the PHP release and every compiler input. To rebuild from the source archive, change into its `php-wasm-build` directory and use its sibling `inputs` directory with the commands above.

## Release

The release owner configures trusted publishing for all three package names and the scaffold in the repository’s protected `release` environment. The tagged release workflow qualifies the three packages, publishes the verified archives, checks registry integrity and installs the scaffold with the published dependencies before publishing the scaffold. Installers use exact registry versions. An npm retry requires the existing registry version to have the expected digest.

The OpenSSL 3 build omits `OPENSSL_SSLV23_PADDING` and rejects numeric RSA padding mode 2. Applications using that mode must migrate the encryption and decryption endpoints together.
