# PHP 8.3 configures OPcache as a Zend extension with its own object list.
libatoms-opcache.la: $(shared_objects_opcache)
	$(LIBTOOL) --tag=CC --mode=link $(CC) -static -o $@ $(shared_objects_opcache)
