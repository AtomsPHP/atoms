#![no_std]

#[allow(non_upper_case_globals, non_camel_case_types, non_snake_case, dead_code)]
mod php {
    include!(env!("ATOMS_PHP_BINDINGS"));
}

mod heap;
mod stack;
mod process_title;
mod crypto;

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
static mut FUNCTIONS: [php::zend_function_entry; 3] = unsafe { zeroed() };
static mut ARG_INFO: [php::zend_internal_arg_info; 2] = unsafe { zeroed() };

extern "C" {
    fn atoms_register_opcache(module_type: i32, module_number: i32) -> i32;
    fn atoms_startup(sapi: *mut php::sapi_module_struct) -> i32;
    fn atoms_roundtrip_i64(value: i64) -> i64;
    fn atoms_send(ptr: *const u8, len: usize) -> i32;
}

// The VM stores arguments immediately after the zval-aligned call frame.
unsafe fn single_arg(frame: *mut php::zend_execute_data, ty: u32) -> Option<*mut php::zval> {
    if (*frame).This.u2.num_args != 1 {
        return None;
    }
    let slots = size_of::<php::zend_execute_data>().div_ceil(size_of::<php::zval>());
    let arg = frame.cast::<php::zval>().add(slots);
    ((*arg).u1.v.type_ == ty as u8).then_some(arg)
}

unsafe extern "C" fn roundtrip(frame: *mut php::zend_execute_data, result: *mut php::zval) {
    if let Some(arg) = single_arg(frame, php::IS_LONG) {
        (*result).value.lval = atoms_roundtrip_i64((*arg).value.lval);
        (*result).u1.type_info = php::IS_LONG;
    } else {
        (*result).u1.type_info = php::IS_NULL;
    }
}

// JS copies the input before it parks. Rust holds no borrow across the import
// and returns an integer, so a Zend allocation failure cannot skip this frame.
unsafe extern "C" fn exchange_length(frame: *mut php::zend_execute_data, result: *mut php::zval) {
    let len = match single_arg(frame, php::IS_STRING) {
        Some(arg) => {
            let string = (*arg).value.str_;
            atoms_send(addr_of!((*string).val).cast(), (*string).len)
        }
        None => -1,
    };
    (*result).value.lval = len.into();
    (*result).u1.type_info = php::IS_LONG;
}

#[no_mangle]
pub unsafe extern "C" fn atoms_use_php_ini(sapi: *mut php::sapi_module_struct) {
    // php_embed_init assigns its CLI defaults after sapi_startup. Let the
    // packaged configuration supply the Worker settings at module startup.
    process_title::register(sapi);
    (*sapi).ini_entries = core::ptr::null();
    (*sapi).php_ini_path_override = c"/internal/shared/php.ini".as_ptr().cast_mut();
}

#[no_mangle]
pub unsafe extern "C" fn atoms_prepare() -> *mut php::zend_module_entry {
    ARG_INFO[0].name = 1usize as *const _; // Zend encodes required_num_args here.
    ARG_INFO[1].name = c"value".as_ptr();
    FUNCTIONS[0].fname = c"atoms_roundtrip_i64".as_ptr();
    FUNCTIONS[0].handler = Some(roundtrip);
    FUNCTIONS[0].arg_info = addr_of!(ARG_INFO).cast();
    FUNCTIONS[0].num_args = 1;

    FUNCTIONS[1].fname = c"atoms_exchange_length".as_ptr();
    FUNCTIONS[1].handler = Some(exchange_length);
    FUNCTIONS[1].arg_info = addr_of!(ARG_INFO).cast();
    FUNCTIONS[1].num_args = 1;

    MODULE.size = size_of::<php::zend_module_entry>() as u16;
    MODULE.zend_api = php::ZEND_MODULE_API_NO;
    MODULE.name = c"atoms_bridge".as_ptr();
    MODULE.version = c"0.1.0".as_ptr();
    MODULE.build_id = concat!(env!("ATOMS_PHP_BUILD_ID"), "\0").as_ptr().cast();
    MODULE.module_startup_func = Some(atoms_register_opcache);
    MODULE.module_shutdown_func = Some(crypto::unload_provider);
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
    if stack::ready() {
        addr_of_mut!(SCRIPT)
    } else {
        core::ptr::null_mut()
    }
}

// libgd has no font cache in the locked build without FreeType. PHP still
// invokes its cleanup at request shutdown through its GD extension.
#[no_mangle]
pub extern "C" fn atoms_gd_font_cache_shutdown() {}
