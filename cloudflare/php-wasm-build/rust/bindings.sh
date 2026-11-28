#!/usr/bin/env bash
set -eu

# Run bindgen against the configured php-src tree and the same emcc target
# headers as the C compiler. Bindgen emits offset/size assertions for Rust.
php_source=$1
builtin_headers=$2
sysroot_headers=$3
output=$4
bindgen "$php_source/sapi/embed/php_embed.h" \
    --use-core --no-doc-comments --no-prepend-enum-name --no-derive-debug \
    --allowlist-type 'zend_(module_entry|execute_data|function_entry|internal_arg_info|file_handle|long|mm_handlers)|zval' \
    --allowlist-var 'php_embed_module|core_globals|USING_ZTS|ZEND_DEBUG|ZEND_MODULE_API_NO|IS_(NULL|LONG|STRING)' \
    --allowlist-function 'zend_mm_(set_heap|get_heap|startup_ex|shutdown)|zend_set_memory_limit' \
    --opaque-type '_zend_(class_entry|function|op|object|array|reference|resource|module_dep|ini_entry)|_hashtable' \
    --formatter none --output "$output" -- \
    -target wasm32-unknown-emscripten -nostdinc \
    -isystem "$builtin_headers" -isystem "$sysroot_headers" \
    -D_GNU_SOURCE -DZEND_ENABLE_ZVAL_LONG64=1 \
    -I"$php_source" -I"$php_source/main" -I"$php_source/TSRM" -I"$php_source/Zend"
