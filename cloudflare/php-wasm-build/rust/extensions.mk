# PHP 8.3 configures OPcache as a Zend extension with its own object list.
libatoms-opcache.la: $(shared_objects_opcache)
	$(LIBTOOL) --tag=CC --mode=link $(CC) -static -o $@ $(shared_objects_opcache)

# Keep OpenSSL's SHAKE defaults at the PHP call sites that supply the buffers.
ext/openssl/openssl.lo: EXTRA_CFLAGS += -DEVP_MD_get_size=atoms_php_digest_size -DEVP_DigestFinal=atoms_php_digest_final -DX509_digest=atoms_php_x509_digest

# PHP probes external GD formats by running target binaries during configure.
# These formats come from the locked GD build and are exercised in the guest.
ext/gd/gd.lo: EXTRA_CFLAGS += -DHAVE_GD_JPG=1 -DHAVE_GD_PNG=1 -DHAVE_GD_WEBP=1 -DHAVE_GD_BMP=1 -DHAVE_GD_AVIF=1 -DHAVE_GD_TGA=1
ext/gd/gd.lo: EXTRA_CFLAGS += -DHAVE_GD_FREETYPE=1 -DgdFontCacheShutdown=atoms_gd_font_cache_shutdown

# Emscripten supplies fnmatch. PHP's POSIX spawn path uses the Worker's
# import for an unavailable executable.
ext/standard/basic_functions.lo ext/standard/file.lo ext/standard/proc_open.lo: EXTRA_CFLAGS += -DHAVE_FNMATCH=1 -DPHP_CAN_SUPPORT_PROC_OPEN=1

# The embed SAPI accepts the process-title handlers from upstream's CLI SAPI.
atoms-process-title.a: sapi/cli/php_cli_process_title.c sapi/cli/ps_title.c
	$(CC) $(COMMON_FLAGS) $(CFLAGS_CLEAN) -c sapi/cli/php_cli_process_title.c -o atoms-cli-title.o
	$(CC) $(COMMON_FLAGS) $(CFLAGS_CLEAN) -c sapi/cli/ps_title.c -o atoms-ps-title.o
	$(AR) rcs $@ atoms-cli-title.o atoms-ps-title.o

# At -O1 even -fno-inline folds the stock wasm32 stack query into a constant
# false in zend_call_stack_init. Preserve the relocation to the Rust adapter.
Zend/zend_call_stack.lo: EXTRA_CFLAGS += -O0
