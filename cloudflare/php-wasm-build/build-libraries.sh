#!/usr/bin/env bash
# Build static Wasm libraries inside the locked, network-disabled builder.
set -euo pipefail
archives=$(realpath "${1:?source archive directory}")
build=$(realpath -m "${2:?empty build directory}")
prefix=$(realpath -m "${3:?empty installation prefix}")
recipe=$(cd "$(dirname "$0")" && pwd)
python3 "$recipe/libraries.py" verify "$archives"
mkdir -p "$build" "$prefix/include" "$prefix/lib/pkgconfig"
export LC_ALL=C TZ=UTC SOURCE_DATE_EPOCH=${SOURCE_DATE_EPOCH:-1704067200} ZERO_AR_DATE=1
export CC=emcc CXX=em++ AR=emar RANLIB=emranlib NM=emnm
export CFLAGS="-O2 -fPIC" CXXFLAGS="-O2 -fPIC"
export CPPFLAGS="-I$prefix/include" LDFLAGS="-L$prefix/lib"
export PKG_CONFIG_LIBDIR="$prefix/lib/pkgconfig" PKG_CONFIG_PATH="$prefix/lib/pkgconfig"
export EM_PKG_CONFIG_PATH="$PKG_CONFIG_PATH"
jobs=${BUILD_JOBS:-4}

# All input archives have one top-level source directory. Bind each to its
# lock entry so two components with overlapping names cannot be confused.
unpack() {
    local name=$1 archive
    archive=$(python3 -c 'import json, sys
print(next(x["file"] for x in json.load(open(sys.argv[1])) if x["name"] == sys.argv[2]))' "$recipe/libraries.lock.json" "$name")
    mkdir "$build/$name"
    tar --no-same-owner -xf "$archives/$archive" -C "$build/$name" --strip-components=1
    cd "$build/$name"
}

configure() {
    emconfigure ./configure --host=wasm32-unknown-emscripten --prefix="$prefix" \
        --disable-shared --enable-static "$@"
}

compile() {
    emmake make -j"$jobs" "$@"
}

cmake_lib() {
    emcmake cmake -S . -B cmake-build -G "Unix Makefiles" \
        -DCMAKE_INSTALL_PREFIX="$prefix" -DCMAKE_INSTALL_LIBDIR=lib \
        -DCMAKE_BUILD_TYPE=Release -DCMAKE_POSITION_INDEPENDENT_CODE=ON \
        -DCMAKE_PREFIX_PATH="$prefix" -DCMAKE_FIND_ROOT_PATH="$prefix;$(em-config CACHE)/sysroot" \
        -DBUILD_SHARED_LIBS=OFF "$@"
    cmake --build cmake-build -j "$jobs"
    cmake --install cmake-build
}

unpack zlib
emconfigure ./configure --prefix="$prefix" --static
compile libz.a
compile install

unpack openssl
# Wasm supplies secure random bytes through Emscripten's getentropy/getrandom.
# Keep the built-in default provider and the standard EVP/PHP API.
./Configure linux-generic32 --prefix="$prefix" --libdir=lib \
    no-shared no-module no-threads no-asm no-tests no-apps no-async \
    no-afalgeng no-dso no-engine -DOPENSSL_NO_SECURE_MEMORY
compile build_generated
compile build_libs
compile install_dev

unpack sqlite
# Match the actual guest's PRAGMA compile_options, including the percentile
# functions introduced upstream and enabled in the SQLite 3.51 release build.
emcc $CFLAGS -DSQLITE_ENABLE_COLUMN_METADATA -DSQLITE_ENABLE_FTS5 \
    -DSQLITE_ENABLE_MATH_FUNCTIONS -DSQLITE_ENABLE_PERCENTILE -DSQLITE_USE_URI \
    -c sqlite3.c -o sqlite3.o
emar rcs "$prefix/lib/libsqlite3.a" sqlite3.o
cp sqlite3.h sqlite3ext.h "$prefix/include/"
sed -e "s|@prefix@|$prefix|g" -e 's|@PACKAGE_VERSION@|3.51.0|g' \
    -e 's|@exec_prefix@|${prefix}|g' -e 's|@libdir@|${exec_prefix}/lib|g' \
    -e 's|@includedir@|${prefix}/include|g' -e 's|@[^@]*@||g' sqlite3.pc.in > "$prefix/lib/pkgconfig/sqlite3.pc"

unpack libiconv
configure --disable-nls
compile lib/localcharset.h
compile -C lib
compile -C lib install
compile -C libcharset install-lib
cp include/iconv.h.inst "$prefix/include/iconv.h"

unpack libxml2
cp /usr/share/misc/config.sub ./config.sub
configure --without-python --without-lzma --without-threads --without-http --without-ftp \
    --with-iconv="$prefix" --with-zlib="$prefix"
compile libxml2.la
compile install-libLTLIBRARIES install-pkgconfigDATA
compile -C include install

unpack oniguruma
configure
compile -C src
compile -C src install
compile install-pkgconfigDATA

unpack curl
# curl 7.69 predates the Emscripten host tuple in GNU config.sub.
cp /usr/share/misc/config.sub ./config.sub
configure --with-ssl="$prefix" --with-zlib="$prefix" --without-libidn2 \
    --without-brotli --without-libssh2 --without-nghttp2 --disable-ldap --disable-ldaps \
    --disable-pop3 --disable-imap --disable-smb --disable-smtp --disable-telnet \
    --disable-gopher --disable-ftp --disable-rtsp --disable-tftp \
    --disable-pthreads --disable-threaded-resolver
compile -C lib
compile -C lib install
compile -C include install
compile install-pkgconfigDATA

unpack libpng
cmake_lib -DPNG_SHARED=OFF -DPNG_STATIC=ON -DPNG_TESTS=OFF -DPNG_TOOLS=OFF \
    -DZLIB_LIBRARY="$prefix/lib/libz.a" -DZLIB_INCLUDE_DIR="$prefix/include"

unpack libjpeg-turbo
cmake_lib -DENABLE_SHARED=OFF -DENABLE_STATIC=ON -DWITH_SIMD=OFF -DWITH_TURBOJPEG=OFF \
    -DBUILD=20240101

unpack libwebp
cmake_lib -DWEBP_BUILD_ANIM_UTILS=OFF -DWEBP_BUILD_CWEBP=OFF -DWEBP_BUILD_DWEBP=OFF \
    -DWEBP_BUILD_GIF2WEBP=OFF -DWEBP_BUILD_IMG2WEBP=OFF -DWEBP_BUILD_VWEBP=OFF \
    -DWEBP_BUILD_WEBPINFO=OFF -DWEBP_BUILD_WEBPMUX=OFF -DWEBP_USE_THREAD=OFF -DWEBP_ENABLE_SIMD=OFF

unpack libaom
cmake_lib -DAOM_TARGET_CPU=generic -DENABLE_TESTS=0 -DENABLE_TESTDATA=0 \
    -DENABLE_TOOLS=0 -DENABLE_DOCS=0 -DENABLE_EXAMPLES=0 -DCONFIG_MULTITHREAD=0 \
    -DCONFIG_RUNTIME_CPU_DETECT=0 -DCONFIG_WEBM_IO=0 -DCONFIG_ACCOUNTING=1

unpack libavif
cmake_lib -DAVIF_CODEC_AOM=SYSTEM -DAVIF_LIBYUV=OFF -DAVIF_BUILD_APPS=OFF \
    -DAVIF_BUILD_TESTS=OFF -DAVIF_LIBSHARPYUV=OFF

unpack libgd
# The avif static build installs a pkg-config file. Its CMake export is
# shared-only, and GD 2.3.3 requests a 0.x CONFIG file explicitly.
sed -i -e 's/FIND_PACKAGE(libavif 0.8.2 REQUIRED CONFIG)/FIND_PACKAGE(PkgConfig REQUIRED)\n\t\tpkg_check_modules(AVIF REQUIRED IMPORTED_TARGET libavif>=0.8.2)/' \
    -e 's/SET(AVIF_LIBRARIES avif)/SET(AVIF_LIBRARIES PkgConfig::AVIF)/' CMakeLists.txt
# gd and PHP use the same name for two different overflow checks. Give gd's
# implementation and callers a private name. Rename gdkanji's translation-
# unit-local iconv fallbacks to avoid colliding with the libc and GNU symbols.
for f in src/*.[ch]; do
    sed -i 's/\<overflow2\>/gd_overflow2/g' "$f"
done
sed -i -e 's/\<iconv_open\>/gdkanji_iconv_open/g' -e 's/\<iconv_close\>/gdkanji_iconv_close/g' \
    -e 's/\<iconv\> (/gdkanji_iconv (/g' src/gdkanji.c
# The shipping guest reports FreeType support, but its drawing functions
# return the libgd "not built with FreeType font support" warning. Retain
# libgd's fallback and the two cache cleanup symbols used by PHP.
cat >>src/gd.c <<'EOF'

#ifndef HAVE_LIBFREETYPE
BGD_DECLARE(void) gdFontCacheShutdown(void) {}
BGD_DECLARE(void) gdFreeFontCache(void) {}
#endif
EOF
cmake_lib -DBUILD_STATIC_LIBS=ON -DBUILD_TEST=OFF -DBUILD_PROGRAMS=OFF \
    -DENABLE_CPP=OFF -DENABLE_GD_FORMATS=ON -DENABLE_PNG=ON -DENABLE_JPEG=ON \
    -DENABLE_WEBP=ON -DENABLE_AVIF=ON -DENABLE_FREETYPE=OFF -DENABLE_ICONV=OFF \
    -DWEBP_LIBRARY="$prefix/lib/libwebp.a" -DWEBP_INCLUDE_DIR="$prefix/include" \
    -DJPEG_LIBRARY="$prefix/lib/libjpeg.a" -DJPEG_INCLUDE_DIR="$prefix/include" \
    -DPNG_LIBRARY="$prefix/lib/libpng16.a" -DPNG_PNG_INCLUDE_DIR="$prefix/include" \
    -DZLIB_LIBRARY="$prefix/lib/libz.a" -DZLIB_INCLUDE_DIR="$prefix/include"

unpack libzip
cmake_lib -DBUILD_TOOLS=OFF -DBUILD_REGRESS=OFF -DBUILD_EXAMPLES=OFF -DBUILD_DOC=OFF \
    -DENABLE_OPENSSL=OFF -DENABLE_GNUTLS=OFF -DENABLE_MBEDTLS=OFF \
    -DENABLE_BZIP2=OFF -DENABLE_LZMA=OFF -DENABLE_ZSTD=OFF \
    -DZLIB_LIBRARY="$prefix/lib/libz.a" -DZLIB_INCLUDE_DIR="$prefix/include"
