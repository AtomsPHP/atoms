#!/usr/bin/env bash
set -eu

php_archive=$1
bridge_archive=$2
output=$3
recipe=$(cd "$(dirname "$0")" && pwd)
emcc -O1 -g2 --no-entry "$bridge_archive" "$php_archive" \
    -Wl,--wrap=start_memory_manager -Wl,--wrap=shutdown_memory_manager \
    --js-library "$recipe/bridge-imports.js" \
    -sWASM_BIGINT=1 -sASYNCIFY=1 -sASYNCIFY_IMPORTS=atoms_send -sALLOW_MEMORY_GROWTH=1 \
    -sMODULARIZE=1 -sEXPORT_ES6=1 -sENVIRONMENT=web,worker,node \
    -sEXPORTED_FUNCTIONS='["_atoms_prepare","_atoms_script_handle","_php_embed_init","_php_embed_shutdown","_php_module_startup","_zend_stream_init_filename","_zend_destroy_file_handle","_php_execute_script","_malloc","_free"]' \
    -sEXPORTED_RUNTIME_METHODS='["FS","HEAPU8","Asyncify"]' \
    -o "$output"
