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
    --allowlist-type 'pdo_(driver_t|dbh_t|stmt_t|bound_param_data|attribute_type|cursor_type|placeholder_support)|zend_(module_entry|execute_data|function_entry|internal_arg_info|file_handle|long|mm_handlers|call_stack)|zval' \
    --allowlist-var 'php_(embed_module|register_internal_extensions_func)|PHP_VERSION|executor_globals|core_globals|PDO_.*|MODULE_DEP_REQUIRED|USING_ZTS|ZEND_DEBUG|ZEND_MODULE_API_NO|IS_.*|GC_STRING|GC_FLAGS_SHIFT|MAY_BE_(NULL|BOOL|STRING|VOID)' \
    --allowlist-function 'zend_mm_(set_heap|get_heap|startup_ex|shutdown)|zend_set_memory_limit' \
    --allowlist-function 'zend_(call_known_function|throw_exception|exception_set_previous|clear_exception|read_property|wrong_parameters_none_error|string_concat2|is_true|hash_(str_find|index_find|index_update|find|update|internal_pointer_reset_ex|get_current_data_ex|move_forward_ex))|zval_(ptr_dtor|get_long_func|try_get_string_func)|_zend_(new_array|handle_numeric_str_ex)|instanceof_function_slow|_e(calloc|free)|_safe_emalloc|add_next_index_(long|str)|php_pdo_(get_exception|stmt_set_column_count)|pdo_raise_impl_error' \
    --opaque-type '_zend_(class_entry|function|op|resource|ini_entry)' \
    --formatter none --output "$output" -- \
    -target wasm32-unknown-emscripten -nostdinc \
    -isystem "$builtin_headers" -isystem "$sysroot_headers" \
    -D_GNU_SOURCE -DZEND_ENABLE_ZVAL_LONG64=1 -DTIMELIB_FORCE_LONG64=1 -DZEND_CHECK_STACK_LIMIT=1 \
    -include main/php.h -include Zend/zend_exceptions.h -include ext/pdo/php_pdo_driver.h \
    -I"$php_source" -I"$php_source/main" -I"$php_source/TSRM" -I"$php_source/Zend"

# The Rust SHAKE adapter calls the public ABI of the linked OpenSSL build.
headers=$(dirname "$builtin_headers")
bindgen "$headers/libs/openssl/x509.h" --use-core --no-doc-comments --no-prepend-enum-name \
    --no-derive-debug --formatter none \
    --allowlist-function 'EVP_(DigestInit_ex|DigestFinal(XOF)?|DigestUpdate|MD_get_(size|type)|MD_CTX_(get0_md|new|free))|i2d_X509|X509_digest|CRYPTO_free|OSSL_PROVIDER_(try_load|unload)' \
    --allowlist-var 'NID_shake(128|256)' --output "$(dirname "$output")/crypto-bindings.rs" -- \
    -x c -Wno-pragma-once-outside-header -fvisibility=default -target wasm32-unknown-emscripten -nostdinc \
    -isystem "$builtin_headers" -isystem "$sysroot_headers" -I"$headers/libs" -include openssl/provider.h
