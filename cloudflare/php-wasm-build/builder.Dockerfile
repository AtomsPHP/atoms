# The build driver supplies the platform digest from compiler.lock.json.
ARG SDK_IMAGE
FROM ${SDK_IMAGE} AS tools
SHELL ["/bin/bash", "-eo", "pipefail", "-c"]
ARG SOURCE_DATE_EPOCH
ENV LC_ALL=C TZ=UTC SOURCE_DATE_EPOCH=$SOURCE_DATE_EPOCH ZERO_AR_DATE=1 \
    EM_CACHE=/em-cache BINARYEN_CORES=1 EMCC_CORES=1 \
    PHP_UNAME="Linux wasm32" PHP_BUILD_SYSTEM="Linux x86_64" \
    EM_PKG_CONFIG_PATH=/libs/lib/pkgconfig BUILD_JOBS=1
WORKDIR /root
COPY debs/ /tmp/debs/
RUN dpkg -i /tmp/debs/*.deb

FROM tools AS libraries
COPY recipe/build-libraries.sh recipe/libraries.py recipe/libraries.lock.json /recipe/
RUN --mount=type=bind,source=libraries,target=/archives \
    bash /recipe/build-libraries.sh /archives /work /libs

FROM tools AS php-source
RUN ln -s /libs /root/lib && ln -s /emsdk /root/emsdk
COPY compile/base-image/replace*.sh compile/base-image/emcc-for-php-wasm.sh /root/
RUN chmod +x /root/replace*.sh && \
    /root/replace.sh "s/src_files\s*\+=\s*glob_in_path\(profile_src_dir,\s*'\*\.cpp'\)//g" /emsdk/upstream/emscripten/tools/system_libs.py && \
    cd /emsdk/upstream/emscripten && cp emcc emcc2 && cp emcc.py emcc2.py && \
    install /root/emcc-for-php-wasm.sh emcc
RUN --mount=type=bind,source=php.tar.gz,target=/tmp/php.tar.gz \
    mkdir php-src && tar -xf /tmp/php.tar.gz --strip-components=1 -C php-src
RUN --mount=type=bind,source=recipe/openssl-legacy-provider.patch,target=/tmp/legacy.patch \
    patch --batch --fuzz=0 -p0 -d php-src -i /tmp/legacy.patch
