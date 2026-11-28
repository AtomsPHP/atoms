// Zend applies its INI policy to these bounds in its own C startup code.
use crate::php;
use core::ffi::c_void;

unsafe extern "C" {
    fn emscripten_stack_get_base() -> usize;
    fn emscripten_stack_get_end() -> usize;
}

static mut INITIALIZED: bool = false;

#[no_mangle]
pub unsafe extern "C" fn __wrap_zend_call_stack_get(stack: *mut php::zend_call_stack) -> bool {
    let base = emscripten_stack_get_base();
    let end = emscripten_stack_get_end();
    if base <= end {
        return false;
    }
    (*stack).base = base as *mut c_void;
    (*stack).max_size = base - end;
    INITIALIZED = true;
    true
}

pub fn ready() -> bool {
    // The NTS interpreter and its JS host execute on one Worker thread.
    unsafe { INITIALIZED }
}
