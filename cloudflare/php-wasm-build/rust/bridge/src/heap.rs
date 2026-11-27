// Zend's mmap storage trims anonymous mappings at both ends to align a chunk.
// Emscripten can release only an entire mapping. Supply complete aligned chunks
// through zend_mm_startup_ex and leave emalloc and memory_limit in Zend.
use super::php;
use core::{ffi::c_void, ptr};

const _: () = assert!(php::USING_ZTS == 0 && php::ZEND_DEBUG == 0);

extern "C" {
    fn posix_memalign(ptr: *mut *mut c_void, alignment: usize, size: usize) -> i32;
    fn free(ptr: *mut c_void);
}

unsafe extern "C" fn allocate(
    _storage: *mut php::zend_mm_storage,
    size: usize,
    alignment: usize,
) -> *mut c_void {
    let mut chunk = ptr::null_mut();
    if posix_memalign(&mut chunk, alignment, size) != 0 {
        return ptr::null_mut();
    }
    // Zend expects anonymous-mapping semantics even for a reused chunk.
    ptr::write_bytes(chunk.cast::<u8>(), 0, size);
    chunk
}

unsafe extern "C" fn release(_storage: *mut php::zend_mm_storage, chunk: *mut c_void, _size: usize) {
    free(chunk);
}

static CHUNKS: php::zend_mm_handlers = php::zend_mm_handlers {
    chunk_alloc: Some(allocate),
    chunk_free: Some(release),
    chunk_extend: None,
    chunk_truncate: None,
};

// The first chunk backs both the heap and its handler record. The public
// constructor returns null if the storage cannot supply that first chunk.
#[no_mangle]
pub unsafe extern "C" fn __wrap_start_memory_manager() {
    let heap = php::zend_mm_startup_ex(&CHUNKS, ptr::null_mut(), 0);
    if heap.is_null() {
        core::arch::wasm32::unreachable();
    }
    php::zend_mm_set_heap(heap);
}

// PHP resets its request heap after module startup and request shutdown. A
// reset makes its own storage descriptor eligible for reuse. Destroy the
// whole heap, then start a new one with a fresh descriptor and the PHP limit.
#[no_mangle]
pub unsafe extern "C" fn __wrap_shutdown_memory_manager(silent: bool, full: bool) {
    let limit = php::core_globals.memory_limit as usize;
    let heap = php::zend_mm_get_heap();
    php::zend_mm_shutdown(heap, true, silent);
    php::zend_mm_set_heap(ptr::null_mut());
    if !full {
        __wrap_start_memory_manager();
        if php::zend_set_memory_limit(limit) != 0 {
            core::arch::wasm32::unreachable();
        }
    }
}
