#!/usr/bin/env bash
set -eu
# Run inside the pinned emsdk image, from the verified 8.3.32 source tree.
# PHP_CHECK_SIZEOF runs a target executable, so its cross defaults need the
# wasm32 libc widths. Emscripten's fork symbol is an ENOSYS stub.
export php_cv_sizeof_intmax_t=8 php_cv_sizeof_ssize_t=4 php_cv_sizeof_ptrdiff_t=4
export ac_cv_func_fork=no
export CC=emcc CXX=em++ AR=emar RANLIB=emranlib
patch --batch --forward --fuzz=0 -p1 -i /recipe/timelib-long64.patch
patch --batch --forward --fuzz=0 -p1 -i /recipe/opcache-file-cache.patch
export EM_PKG_CONFIG_PATH=/libs/lib/pkgconfig
export CPPFLAGS=-I/libs/include LDFLAGS=-L/libs/lib
export LIBS="-lgd -lavif -laom -ljpeg -lwebp -lsharpyuv -lpng16 -lcurl -lssl -lcrypto -lzip -lz -liconv"
export CFLAGS="-O1 -DZEND_ENABLE_ZVAL_LONG64=1 -DTIMELIB_FORCE_LONG64=1 -DZEND_CHECK_STACK_LIMIT=1"
./buildconf --force
emconfigure ./configure --build=x86_64-pc-linux-gnu --host=wasm32-unknown-emscripten \
    --disable-all --disable-cli --disable-cgi --disable-phpdbg --enable-zend-signals \
    --disable-fiber-asm --without-pcre-jit --without-pear --enable-embed=static \
    --enable-bcmath --enable-calendar --enable-ctype --enable-exif --enable-fileinfo \
    --enable-filter --enable-phar --enable-session --enable-tokenizer \
    --enable-opcache --disable-opcache-jit --disable-huge-code-pages \
    --enable-mbstring --enable-mbregex --with-zlib --with-zip --enable-gd --with-external-gd \
    --with-libxml --enable-dom --enable-soap --enable-simplexml --enable-xml --enable-xmlreader --enable-xmlwriter \
    --with-sqlite3 --enable-pdo --with-pdo-sqlite --with-iconv=/libs --with-openssl --with-curl \
    --with-layout=GNU --with-config-file-path=/usr/local/etc
emmake make -f Makefile -f /recipe/extensions.mk -j"${BUILD_JOBS:-1}" libphp.la libatoms-opcache.la atoms-process-title.a
