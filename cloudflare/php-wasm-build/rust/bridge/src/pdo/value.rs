use super::engine::{self, Result};
use crate::php::{self, zend_string, zval};
use core::ffi::CStr;
use core::mem::zeroed;
use core::ptr::{addr_of, null_mut};

pub(super) unsafe fn kind(value: *const zval) -> u32 {
    (*value).u1.v.type_ as u32
}
pub(super) unsafe fn undefined() -> zval {
    zeroed()
}
pub(super) unsafe fn scalar(kind: u32, number: i64) -> zval {
    let mut value = undefined();
    value.u1.type_info = kind;
    value.value.lval = number;
    value
}
pub(super) unsafe fn bytes<'a>(value: *const zend_string) -> &'a [u8] {
    core::slice::from_raw_parts(addr_of!((*value).val).cast(), (*value).len)
}
pub(super) unsafe fn string_value(string: *mut zend_string) -> zval {
    let mut value = undefined();
    value.value.str_ = string;
    value.u1.type_info =
        if (*string).gc.u.type_info & (php::IS_STR_INTERNED << php::GC_FLAGS_SHIFT) == 0 {
            php::IS_STRING_EX
        } else {
            php::IS_STRING
        };
    value
}
pub(super) unsafe fn new_string(text: &CStr) -> Result<zval> {
    Ok(string_value(engine::zend_string_concat2(
        text.as_ptr(),
        text.to_bytes().len(),
        c"".as_ptr(),
        0,
    )?))
}
pub(super) unsafe fn copy(value: *const zval) -> zval {
    let result = *value;
    if result.u1.v.type_flags != 0 {
        (*result.value.counted).gc.refcount += 1;
    }
    result
}
pub(super) unsafe fn deref(mut value: *mut zval) -> *mut zval {
    if kind(value) == php::IS_REFERENCE {
        value = &raw mut (*(*value).value.ref_).val;
    }
    value
}
pub(super) unsafe fn discard(value: *mut zval) -> Result<()> {
    let mut owned = core::ptr::replace(value, undefined());
    engine::zval_ptr_dtor(&mut owned)
}
pub(super) unsafe fn key(array: *const zval, name: &CStr) -> *mut zval {
    if kind(array) != php::IS_ARRAY {
        return null_mut();
    }
    php::zend_hash_str_find((*array).value.arr, name.as_ptr(), name.to_bytes().len())
}
pub(super) unsafe fn index(array: *const zval, n: u64) -> *mut zval {
    if kind(array) != php::IS_ARRAY {
        return null_mut();
    }
    php::zend_hash_index_find((*array).value.arr, n)
}
