// Attach upstream's CLI process-title handlers to the embed SAPI. PHP
// registers the SAPI's additional functions under its standard extension.
use crate::php;
use core::mem::zeroed;
use core::ptr::{addr_of, read};

unsafe extern "C" {
    fn zif_cli_get_process_title(frame: *mut php::zend_execute_data, result: *mut php::zval);
    fn zif_cli_set_process_title(frame: *mut php::zend_execute_data, result: *mut php::zval);
}

static mut FUNCTIONS: [php::zend_function_entry; 4] = unsafe { zeroed() };
static mut GET_ARGS: php::zend_internal_arg_info = unsafe { zeroed() };
static mut SET_ARGS: [php::zend_internal_arg_info; 2] = unsafe { zeroed() };

pub unsafe fn register(sapi: *mut php::sapi_module_struct) {
    // The first entry in the stock PHP 8.3 embed table is dl().
    FUNCTIONS[0] = read((*sapi).additional_functions);
    GET_ARGS.type_.type_mask = php::MAY_BE_STRING | php::MAY_BE_NULL;
    FUNCTIONS[1].fname = c"cli_get_process_title".as_ptr();
    FUNCTIONS[1].handler = Some(zif_cli_get_process_title);
    FUNCTIONS[1].arg_info = addr_of!(GET_ARGS);

    SET_ARGS[0].name = 1usize as *const _; // One required argument.
    SET_ARGS[0].type_.type_mask = php::MAY_BE_BOOL;
    SET_ARGS[1].name = c"title".as_ptr();
    SET_ARGS[1].type_.type_mask = php::MAY_BE_STRING;
    FUNCTIONS[2].fname = c"cli_set_process_title".as_ptr();
    FUNCTIONS[2].handler = Some(zif_cli_set_process_title);
    FUNCTIONS[2].arg_info = addr_of!(SET_ARGS).cast();
    FUNCTIONS[2].num_args = 1;
    (*sapi).additional_functions = addr_of!(FUNCTIONS).cast();
}
