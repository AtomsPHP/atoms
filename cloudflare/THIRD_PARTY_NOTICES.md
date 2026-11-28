# Third-party notices for the Atoms Cloudflare Worker

Atoms ships each PHP Wasm interpreter and matching JavaScript glue in
`@atomsphp/php-wasm-8-3`, `@atomsphp/php-wasm-8-4` or `@atomsphp/php-wasm-8-5`.
Each npm tarball includes `THIRD_PARTY_NOTICES.txt` with the component copyright
notices and grants, `components.json` with the source identities and
`corresponding-source.tar.gz` with the source used for that build.

The interpreter links the upstream PHP embed SAPI, Zend, Rust runtime code,
Emscripten runtime code, OpenSSL and the libraries named in `components.json`.
This software is based in part on the work of the Independent JPEG Group.
The Atoms-authored build scripts, Rust bridge and Worker loader are covered
by the repository’s MIT grant.

The corresponding source guide describes how to verify and rebuild an installed
package. [Corresponding source guide](https://github.com/AtomsPHP/atoms/tree/main/cloudflare/corresponding-source)
