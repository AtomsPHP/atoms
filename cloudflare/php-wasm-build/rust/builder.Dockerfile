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
ARG PHP_EMBED
ARG LIBRARY_JOBS=1
COPY recipe/build-libraries.sh recipe/libraries.py recipe/libraries.lock.json /recipe/
RUN --mount=type=bind,source=libraries,target=/archives \
    test ! -e /em-cache && test "$(em-config CACHE)" = /em-cache && \
    ATOMS_PHP_EMBED="$PHP_EMBED" BUILD_JOBS="$LIBRARY_JOBS" bash /recipe/build-libraries.sh /archives /work /libs
