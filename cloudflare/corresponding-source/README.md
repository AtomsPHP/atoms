# Corresponding source for the PHP interpreter

The registry lock selects an immutable Atoms package for each PHP minor.
Every installed package at `node_modules/@atomsphp/php-wasm-8-<minor>`
contains the following files alongside its Wasm and JavaScript glue.

| File | Contents |
| --- | --- |
| `corresponding-source.tar.gz` | Source archives, patches, build scripts and locked toolchain inputs for this interpreter |
| `components.json` | Component versions, upstream source identities, licenses and source-file digests |
| `THIRD_PARTY_NOTICES.txt` | Copyright and license notices for the combined work and its components |
| `build-metadata.json` | Build recipe hashes and SHA-256 digests of the final Wasm and glue |

The source is distributed in the same npm tarball as the binary. To inspect
it after installing the scaffold, run, for example,

```sh
tar -tzf node_modules/@atomsphp/php-wasm-8-3/corresponding-source.tar.gz
```

In the Atoms repository, the build recipes are under
`cloudflare/php-wasm-build/`. The release pin for each minor is
`targets/<major.minor>/candidate.json` in that directory. Qualification compares
two clean builds and records the package integrity, Wasm and glue identity
and measured guest inventory. The scaffold verifies each installed pair
before replacing its loader.
