# Third-party notices for the Atoms Cloudflare Worker

Atoms publishes the Wasm interpreter and its JavaScript glue in
`@atomsphp/php-wasm-8-3`, `@atomsphp/php-wasm-8-4` and `@atomsphp/php-wasm-8-5`.
Each package contains `THIRD_PARTY_NOTICES.txt` with its component copyright
notices and license texts, `components.json` with its source inventory, and
`corresponding-source.tar.gz`. These files accompany the exact binary selected
for deployment. The combined interpreter and glue are offered under GPLv3.

The build uses the WordPress Playground web SAPI, PHP patches and extensions
under GPL-2.0-or-later, PHP and Zend under the recorded BSD election,
and OpenSSL 3.5.9 under Apache-2.0. The component notices include the grants
for the linked libraries and Emscripten runtime. This software is based in
part on the work of the Independent JPEG Group.

The Worker also uses the Playground JavaScript library `@php-wasm/universal`
3.1.48 and its dependencies `@php-wasm/logger`, `@php-wasm/util` and
`@php-wasm/stream-compression`. Their npm distributions carry their notices.
Playground declares GPL-2.0-or-later for the host library. The util package
ships the GPLv2 license text and omits the license field in its manifest.

Atoms-authored source is covered by the Atoms MIT grant. The
[corresponding source guide](https://github.com/AtomsPHP/atoms/tree/main/cloudflare/corresponding-source) identifies the
build inputs and the verification procedure for each package.
