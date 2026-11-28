#![no_std]

#[allow(non_upper_case_globals, non_camel_case_types, non_snake_case, dead_code)]
mod php {
    include!(env!("ATOMS_PHP_BINDINGS"));
}

mod heap;

use core::mem::{size_of, zeroed};
use core::ptr::{addr_of, addr_of_mut};

const _: () = assert!(size_of::<usize>() == 4);
const _: () = assert!(size_of::<php::zend_long>() == 8);
const _: () = assert!(size_of::<php::zval>() == 16);

#[panic_handler]
fn abort(_: &core::panic::PanicInfo<'_>) -> ! {
    core::arch::wasm32::unreachable()
}

// The embedding owns one non-thread-safe PHP interpreter per Wasm instance.
// Zend mutates the module record during startup. Its tables live until teardown.
static mut MODULE: php::zend_module_entry = unsafe { zeroed() };
static mut FUNCTIONS: [php::zend_function_entry; 2] = unsafe { zeroed() };
static mut ARG_INFO: [php::zend_internal_arg_info; 2] = unsafe { zeroed() };

extern "C" {
    fn atoms_startup(sapi: *mut php::sapi_module_struct) -> i32;
    fn atoms_roundtrip_i64(value: i64) -> i64;
}

// Compute ZEND_CALL_ARG(frame, 1) using the configured target layouts.
// No Zend calls, allocations or Rust references survive the host call.
unsafe extern "C" fn roundtrip(frame: *mut php::zend_execute_data, result: *mut php::zval) {
    if (*frame).This.u2.num_args == 1 {
        let slots = size_of::<php::zend_execute_data>().div_ceil(size_of::<php::zval>());
        let arg = frame.cast::<php::zval>().add(slots);
        if (*arg).u1.v.type_ == php::IS_LONG as u8 {
            (*result).value.lval = atoms_roundtrip_i64((*arg).value.lval);
            (*result).u1.type_info = php::IS_LONG;
            return;
        }
    }
    (*result).u1.type_info = php::IS_NULL;
}

#[no_mangle]
pub unsafe extern "C" fn atoms_prepare() -> *mut php::zend_module_entry {
    ARG_INFO[0].name = 1usize as *const _; // Zend encodes required_num_args here.
    ARG_INFO[1].name = c"value".as_ptr();
    FUNCTIONS[0].fname = c"atoms_roundtrip_i64".as_ptr();
    FUNCTIONS[0].handler = Some(roundtrip);
    FUNCTIONS[0].arg_info = addr_of!(ARG_INFO).cast();
    FUNCTIONS[0].num_args = 1;

    MODULE.size = size_of::<php::zend_module_entry>() as u16;
    MODULE.zend_api = php::ZEND_MODULE_API_NO;
    MODULE.name = c"atoms_bridge".as_ptr();
    MODULE.version = c"0.1.0".as_ptr();
    MODULE.build_id = concat!(env!("ATOMS_PHP_BUILD_ID"), "\0").as_ptr().cast();
    MODULE.functions = addr_of!(FUNCTIONS).cast();

    php::php_embed_module.name = c"wasm".as_ptr().cast_mut();
    php::php_embed_module.startup = Some(atoms_startup);
    addr_of_mut!(MODULE)
}

// One primary script per embed request. JS invokes the upstream Zend and PHP
// entry points directly, keeping Rust off the script execution/bailout stack.
static mut SCRIPT: php::zend_file_handle = unsafe { zeroed() };

#[no_mangle]
pub extern "C" fn atoms_script_handle() -> *mut php::zend_file_handle {
    addr_of_mut!(SCRIPT)
}
