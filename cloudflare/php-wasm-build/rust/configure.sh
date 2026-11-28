#!/usr/bin/env bash
set -eu
# Run inside the pinned emsdk image, from the verified 8.3.32 source tree.
# PHP_CHECK_SIZEOF runs a target executable, so its cross defaults need the
# wasm32 libc widths. Emscripten's fork symbol is an ENOSYS stub.
export php_cv_sizeof_intmax_t=8 php_cv_sizeof_ssize_t=4 php_cv_sizeof_ptrdiff_t=4
export ac_cv_func_fork=no
export CC=emcc CXX=em++ AR=emar RANLIB=emranlib
export CFLAGS="-O1 -DZEND_ENABLE_ZVAL_LONG64=1"
./buildconf --force
emconfigure ./configure --build=x86_64-pc-linux-gnu --host=wasm32-unknown-emscripten \
    --disable-all --disable-cli --disable-cgi --disable-phpdbg --disable-zend-signals \
    --disable-fiber-asm --without-pcre-jit --without-pear --enable-embed=static
emmake make -j"${BUILD_JOBS:-1}"
