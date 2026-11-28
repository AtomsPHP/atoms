//! A Zend call may run PHP, including destructors, or fail an allocation. The
//! JavaScript leaf returns a status before the outer PDO callback rethrows.
use crate::php::*;
use core::ffi::{c_char, c_void};

#[derive(Debug)]
pub(super) struct Bailout;
pub(super) type Result<T> = core::result::Result<T, Bailout>;

#[repr(C)]
struct Argument {
    bits: u64,
    wide: u64,
}

trait WasmValue: Copy {
    fn argument(self) -> Argument;
    fn from_result(bits: u64) -> Self;
}

macro_rules! integer {
    ($ty:ty, $wide:expr) => {
        impl WasmValue for $ty {
            fn argument(self) -> Argument {
                Argument {
                    bits: self as u64,
                    wide: $wide,
                }
            }
            fn from_result(bits: u64) -> Self {
                bits as Self
            }
        }
    };
}
integer!(u32, 0);
integer!(i32, 0);
integer!(usize, 0);
integer!(i64, 1);
integer!(u64, 1);
impl WasmValue for bool {
    fn argument(self) -> Argument {
        (self as u32).argument()
    }
    fn from_result(bits: u64) -> Self {
        bits != 0
    }
}
impl<T> WasmValue for *mut T {
    fn argument(self) -> Argument {
        (self as usize).argument()
    }
    fn from_result(bits: u64) -> Self {
        bits as Self
    }
}
impl<T> WasmValue for *const T {
    fn argument(self) -> Argument {
        (self as usize).argument()
    }
    fn from_result(bits: u64) -> Self {
        bits as Self
    }
}
impl WasmValue for () {
    fn argument(self) -> Argument {
        0u32.argument()
    }
    fn from_result(_: u64) -> Self {}
}

extern "C" {
    fn atoms_pdo_leaf(
        function: usize,
        args: *const Argument,
        count: usize,
        output: *mut u64,
    ) -> i32;
}

// Assigning the generated binding to this signature checks the Wasm argument
// widths and return type against the configured headers at compile time.
macro_rules! leaf {
    (fn $name:ident ($($arg:ident: $ty:ty),*) -> $out:ty) => {
        pub(super) unsafe fn $name($($arg: $ty),*) -> Result<$out> {
            let function: unsafe extern "C" fn($($ty),*) -> $out = crate::php::$name;
            let args: &[Argument] = &[$($arg.argument()),*];
            let mut result = 0;
            if atoms_pdo_leaf(function as usize, args.as_ptr(), args.len(), &mut result) != 0 {
                return Err(Bailout);
            }
            Ok(<$out>::from_result(result))
        }
    };
}
leaf!(fn _safe_emalloc(count: usize, size: usize, offset: usize) -> *mut c_void);
leaf!(fn _ecalloc(count: usize, size: usize) -> *mut c_void);
leaf!(fn _efree(ptr: *mut c_void) -> ());
leaf!(fn zend_call_known_function(function: *mut zend_function, object: *mut zend_object, scope: *mut zend_class_entry, result: *mut zval, count: u32, args: *mut zval, named: *mut HashTable) -> ());
leaf!(fn zend_throw_exception(class: *mut zend_class_entry, message: *const c_char, code: zend_long) -> *mut zend_object);
leaf!(fn zend_exception_set_previous(exception: *mut zend_object, previous: *mut zend_object) -> ());
leaf!(fn zend_clear_exception() -> ());
leaf!(fn zval_ptr_dtor(value: *mut zval) -> ());
leaf!(fn zend_string_concat2(first: *const c_char, first_len: usize, second: *const c_char, second_len: usize) -> *mut zend_string);
leaf!(fn _zend_new_array(size: u32) -> *mut HashTable);
leaf!(fn zend_hash_index_update(table: *mut HashTable, index: zend_ulong, value: *mut zval) -> *mut zval);
leaf!(fn zend_hash_update(table: *mut HashTable, key: *mut zend_string, value: *mut zval) -> *mut zval);
leaf!(fn zend_read_property(class: *mut zend_class_entry, object: *mut zend_object, name: *const c_char, len: usize, silent: bool, result: *mut zval) -> *mut zval);
leaf!(fn add_next_index_long(array: *mut zval, value: zend_long) -> zend_result);
leaf!(fn add_next_index_str(array: *mut zval, value: *mut zend_string) -> zend_result);
leaf!(fn zend_is_true(value: *const zval) -> i32);
leaf!(fn zval_get_long_func(value: *const zval, strict: bool) -> zend_long);
leaf!(fn zval_try_get_string_func(value: *mut zval) -> *mut zend_string);
leaf!(fn php_pdo_stmt_set_column_count(stmt: *mut pdo_stmt_t, count: i32) -> ());
leaf!(fn pdo_raise_impl_error(dbh: *mut pdo_dbh_t, stmt: *mut pdo_stmt_t, state: *mut c_char, text: *const c_char) -> ());
leaf!(fn zend_wrong_parameters_none_error() -> ());
