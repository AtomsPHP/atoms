mod engine;
mod statement;
mod value;

use crate::php::{self, pdo_dbh_t, pdo_stmt_t, zend_string, zval};
use core::ffi::{c_char, CStr};
use core::mem::{offset_of, size_of, zeroed};
use core::ptr::{addr_of, addr_of_mut, null_mut};
use engine::Result;
use value::*;

// Zend registers the tables once for this NTS Wasm instance. Each JS entry
// rethrows a saved engine bailout only after its Rust callback returns.
static mut MODULE: php::zend_module_entry = unsafe { zeroed() };
static mut DEPENDENCIES: [php::_zend_module_dep; 2] = unsafe { zeroed() };
static mut FUNCTIONS: [php::zend_function_entry; 2] = unsafe { zeroed() };
static mut CLOSE_INFO: php::zend_internal_arg_info = unsafe { zeroed() };
static mut DRIVER: php::pdo_driver_t = unsafe { zeroed() };
static mut METHODS: php::pdo_dbh_methods = unsafe { zeroed() };
static mut STATEMENTS: php::pdo_stmt_methods = unsafe { zeroed() };

extern "C" {
    pub(super) fn atoms_register_builtin_pdo() -> i32;
    fn atoms_pdo_minit(module_type: i32, module_number: i32) -> i32;
    fn atoms_pdo_mshutdown(module_type: i32, module_number: i32) -> i32;
    fn atoms_pdo_rshutdown(module_type: i32, module_number: i32) -> i32;
    fn atoms_pdo_close_cursors(frame: *mut php::zend_execute_data, result: *mut zval);
    fn atoms_pdo_open(dbh: *mut pdo_dbh_t, options: *mut zval) -> i32;
    fn atoms_pdo_close(dbh: *mut pdo_dbh_t);
    fn atoms_pdo_prepare(
        dbh: *mut pdo_dbh_t,
        sql: *mut zend_string,
        stmt: *mut pdo_stmt_t,
        options: *mut zval,
    ) -> bool;
    fn atoms_pdo_exec(dbh: *mut pdo_dbh_t, sql: *const zend_string) -> i64;
    fn atoms_pdo_quote(
        dbh: *mut pdo_dbh_t,
        sql: *const zend_string,
        ty: php::pdo_param_type,
    ) -> *mut zend_string;
    fn atoms_pdo_begin(dbh: *mut pdo_dbh_t) -> bool;
    fn atoms_pdo_commit(dbh: *mut pdo_dbh_t) -> bool;
    fn atoms_pdo_rollback(dbh: *mut pdo_dbh_t) -> bool;
    fn atoms_pdo_owns(dbh: *mut pdo_dbh_t) -> bool;
    fn atoms_pdo_last_id(dbh: *mut pdo_dbh_t, name: *const zend_string) -> *mut zend_string;
    fn atoms_pdo_error_info(dbh: *mut pdo_dbh_t, stmt: *mut pdo_stmt_t, info: *mut zval);
    fn atoms_pdo_destroy_stmt(stmt: *mut pdo_stmt_t) -> i32;
    fn atoms_pdo_execute(stmt: *mut pdo_stmt_t) -> i32;
    fn atoms_pdo_fetch(
        stmt: *mut pdo_stmt_t,
        orientation: php::pdo_fetch_orientation,
        offset: i64,
    ) -> i32;
    fn atoms_pdo_describe(stmt: *mut pdo_stmt_t, col: i32) -> i32;
    fn atoms_pdo_column(
        stmt: *mut pdo_stmt_t,
        col: i32,
        result: *mut zval,
        ty: *mut php::pdo_param_type,
    ) -> i32;
    fn atoms_pdo_close_cursor(stmt: *mut pdo_stmt_t) -> i32;
}

#[no_mangle]
pub unsafe extern "C" fn atoms_pdo_module() -> *mut php::zend_module_entry {
    DEPENDENCIES[0].name = c"pdo".as_ptr();
    DEPENDENCIES[0].type_ = php::MODULE_DEP_REQUIRED as u8;
    CLOSE_INFO.type_.type_mask = php::MAY_BE_VOID;
    FUNCTIONS[0].fname = c"Atoms\\Cf\\native_pdo_close_cursors".as_ptr();
    FUNCTIONS[0].handler = Some(atoms_pdo_close_cursors);
    FUNCTIONS[0].arg_info = addr_of!(CLOSE_INFO);
    MODULE.size = size_of::<php::zend_module_entry>() as u16;
    MODULE.zend_api = php::ZEND_MODULE_API_NO;
    MODULE.name = c"pdo_atoms".as_ptr();
    MODULE.version = php::PHP_VERSION.as_ptr().cast();
    MODULE.build_id = concat!(env!("ATOMS_PHP_BUILD_ID"), "\0").as_ptr().cast();
    MODULE.deps = addr_of!(DEPENDENCIES).cast();
    MODULE.functions = addr_of!(FUNCTIONS).cast();
    MODULE.module_startup_func = Some(atoms_pdo_minit);
    MODULE.module_shutdown_func = Some(atoms_pdo_mshutdown);
    MODULE.request_shutdown_func = Some(atoms_pdo_rshutdown);
    DRIVER.driver_name = c"atoms".as_ptr();
    DRIVER.driver_name_len = 5;
    DRIVER.api_version = php::PDO_DRIVER_API.into();
    DRIVER.db_handle_factory = Some(atoms_pdo_open);
    METHODS.closer = Some(atoms_pdo_close);
    METHODS.preparer = Some(atoms_pdo_prepare);
    METHODS.doer = Some(atoms_pdo_exec);
    METHODS.quoter = Some(atoms_pdo_quote);
    METHODS.begin = Some(atoms_pdo_begin);
    METHODS.commit = Some(atoms_pdo_commit);
    METHODS.rollback = Some(atoms_pdo_rollback);
    METHODS.in_transaction = Some(atoms_pdo_owns);
    METHODS.last_id = Some(atoms_pdo_last_id);
    METHODS.fetch_err = Some(atoms_pdo_error_info);
    STATEMENTS.dtor = Some(atoms_pdo_destroy_stmt);
    STATEMENTS.executer = Some(atoms_pdo_execute);
    STATEMENTS.fetcher = Some(atoms_pdo_fetch);
    STATEMENTS.describer = Some(atoms_pdo_describe);
    STATEMENTS.get_col = Some(atoms_pdo_column);
    STATEMENTS.cursor_closer = Some(atoms_pdo_close_cursor);
    addr_of_mut!(MODULE)
}
#[no_mangle]
pub extern "C" fn atoms_pdo_driver() -> *const php::pdo_driver_t {
    addr_of!(DRIVER)
}

struct Handle {
    token: i64,
    diagnostics: Diagnostics,
}
struct Diagnostics {
    state: [c_char; 6],
    code: i64,
    message: zval,
}

unsafe fn error(text: &CStr) -> Result<()> {
    engine::zend_throw_exception(php::php_pdo_get_exception(), text.as_ptr(), 0)?;
    Ok(())
}

unsafe fn bridge(
    dbh: *mut pdo_dbh_t,
    op: &CStr,
    sql: *const zend_string,
    bindings: *mut zval,
) -> Result<Option<zval>> {
    let name = c"atoms\\cf\\native_pdo";
    let entry = php::zend_hash_str_find(
        php::executor_globals.function_table,
        name.as_ptr(),
        name.to_bytes().len(),
    );
    if entry.is_null() {
        error(c"PDO('atoms:') requires an active Atom.")?;
        return Ok(None);
    }
    let handle = (*dbh).driver_data.cast::<Handle>();
    let token = if handle.is_null() { 0 } else { (*handle).token };
    let mut args = [
        new_string(op)?,
        scalar(php::IS_LONG, token),
        undefined(),
        undefined(),
    ];
    let mut count = 2;
    if !sql.is_null() {
        args[2] = string_value(sql.cast_mut());
        count = 3;
        if !bindings.is_null() {
            args[3] = *bindings;
            count = 4;
        }
    }
    let mut result = undefined();
    // PDO can invoke owns/rollback while cleaning up a pending exception.
    let pending = php::executor_globals.exception;
    let site = php::executor_globals.opline_before_exception;
    php::executor_globals.exception = null_mut();
    engine::zend_call_known_function(
        (*entry).value.func,
        null_mut(),
        null_mut(),
        &mut result,
        count,
        args.as_mut_ptr(),
        null_mut(),
    )?;
    let raised = php::executor_globals.exception;
    if !pending.is_null() {
        if !raised.is_null() {
            engine::zend_exception_set_previous(pending, raised)?;
        }
        php::executor_globals.exception = pending;
        php::executor_globals.opline_before_exception = site;
    }
    discard(&mut args[0])?;
    if !raised.is_null() {
        discard(&mut result)?;
        return Ok(None);
    }
    Ok(Some(result))
}

unsafe fn check(dbh: *mut pdo_dbh_t, op: &CStr) -> Result<bool> {
    let Some(mut result) = bridge(dbh, op, null_mut(), null_mut())? else {
        return Ok(false);
    };
    discard(&mut result)?;
    Ok(true)
}

unsafe fn bare_dsn() -> bool {
    let frame = php::executor_globals.current_execute_data;
    if frame.is_null() || (*frame).This.u2.num_args == 0 {
        return false;
    }
    let args = frame
        .cast::<zval>()
        .add(size_of::<php::zend_execute_data>().div_ceil(size_of::<zval>()));
    let dsn = deref(args);
    if kind(dsn) != php::IS_STRING || bytes((*dsn).value.str_) != b"atoms:" {
        return false;
    }
    for i in 1..(*frame).This.u2.num_args.min(3) {
        let arg = deref(args.add(i as usize));
        match kind(arg) {
            php::IS_UNDEF | php::IS_NULL => (),
            php::IS_STRING if (*(*arg).value.str_).len == 0 => (),
            _ => return false,
        }
    }
    true
}

unsafe fn open(dbh: *mut pdo_dbh_t) -> Result<i32> {
    if !bare_dsn() {
        error(c"Use the bare atoms: DSN without credentials.")?;
        return Ok(0);
    }
    if (*dbh).is_persistent() != 0 {
        error(c"Persistent Atom database connections are unsupported.")?;
        return Ok(0);
    }
    let Some(mut result) = bridge(dbh, c"open", null_mut(), null_mut())? else {
        return Ok(0);
    };
    if kind(&result) != php::IS_LONG || result.value.lval <= 0 {
        discard(&mut result)?;
        error(c"Invalid Atom database handle.")?;
        return Ok(0);
    }
    let handle = engine::_ecalloc(1, size_of::<Handle>())?.cast::<Handle>();
    (*handle).token = result.value.lval;
    (*dbh).driver_data = handle.cast();
    (*dbh).methods = addr_of!(METHODS);
    (*dbh).set_alloc_own_columns(1);
    Ok(1)
}

unsafe fn close(dbh: *mut pdo_dbh_t) -> Result<()> {
    let handle = core::ptr::replace(&raw mut (*dbh).driver_data, null_mut()).cast::<Handle>();
    if !handle.is_null() {
        discard(&raw mut (*handle).diagnostics.message)?;
        engine::_efree(handle.cast())?;
    }
    Ok(())
}

unsafe fn quote(dbh: *mut pdo_dbh_t, input: *const zend_string) -> Result<*mut zend_string> {
    let data = bytes(input);
    if data.contains(&0) {
        error(c"PDO::quote() refuses a value containing a NUL byte.")?;
        return Ok(null_mut());
    }
    let input_len = data.len();
    let extra = data.iter().filter(|&&b| b == b'\'').count() + 2;
    let header = offset_of!(zend_string, val) + 1;
    let result = engine::_safe_emalloc(1, input_len, extra + header)?.cast::<zend_string>();
    let len = input_len + extra;
    (*result).gc.refcount = 1;
    (*result).gc.u.type_info = php::GC_STRING;
    (*result).h = 0;
    (*result).len = len;
    let mut out = addr_of_mut!((*result).val).cast::<u8>();
    out.write(b'\'');
    out = out.add(1);
    for &ch in bytes(input) {
        if ch == b'\'' {
            out.write(ch);
            out = out.add(1);
        }
        out.write(ch);
        out = out.add(1);
    }
    out.write(b'\'');
    out.add(1).write(0);
    discard(&raw mut (*(*dbh).driver_data.cast::<Handle>()).diagnostics.message)?;
    Ok(result)
}

#[no_mangle]
pub unsafe extern "C" fn atoms_rs_open(dbh: *mut pdo_dbh_t) -> i32 {
    open(dbh).unwrap_or(0)
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_close(dbh: *mut pdo_dbh_t) {
    let _ = close(dbh);
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_quote(
    dbh: *mut pdo_dbh_t,
    input: *const zend_string,
) -> *mut zend_string {
    quote(dbh, input).unwrap_or(null_mut())
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_begin(dbh: *mut pdo_dbh_t) -> bool {
    check(dbh, c"begin").unwrap_or(false)
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_commit(dbh: *mut pdo_dbh_t) -> bool {
    check(dbh, c"commit").unwrap_or(false)
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_rollback(dbh: *mut pdo_dbh_t) -> bool {
    check(dbh, c"rollback").unwrap_or(false)
}

unsafe fn diagnostics(dbh: *mut pdo_dbh_t, stmt: *mut pdo_stmt_t) -> *mut Diagnostics {
    if stmt.is_null() {
        &raw mut (*(*dbh).driver_data.cast::<Handle>()).diagnostics
    } else {
        statement::diagnostics(stmt)
    }
}
unsafe fn state(dbh: *mut pdo_dbh_t, stmt: *mut pdo_stmt_t) -> *mut [c_char; 6] {
    if stmt.is_null() {
        &raw mut (*dbh).error_code
    } else {
        &raw mut (*stmt).error_code
    }
}
unsafe fn sql_error(dbh: *mut pdo_dbh_t, stmt: *mut pdo_stmt_t) -> Result<()> {
    let exception = php::executor_globals.exception;
    let class = php::php_pdo_get_exception();
    if exception.is_null()
        || ((*exception).ce != class && !php::instanceof_function_slow((*exception).ce, class))
    {
        return Ok(());
    }
    let mut scratch = undefined();
    let info = engine::zend_read_property(
        class,
        exception,
        c"errorInfo".as_ptr(),
        9,
        true,
        &mut scratch,
    )?;
    let (sqlstate, code, text) = (index(info, 0), index(info, 1), index(info, 2));
    if sqlstate.is_null()
        || kind(sqlstate) != php::IS_STRING
        || (*(*sqlstate).value.str_).len != 5
        || code.is_null()
        || kind(code) != php::IS_LONG
        || text.is_null()
        || kind(text) != php::IS_STRING
    {
        return Ok(());
    }
    let saved = diagnostics(dbh, stmt);
    core::ptr::copy_nonoverlapping(
        addr_of!((*(*sqlstate).value.str_).val).cast::<c_char>(),
        (*saved).state.as_mut_ptr(),
        6,
    );
    *state(dbh, stmt) = (*saved).state;
    (*saved).code = (*code).value.lval;
    (*saved).message = copy(text);
    if (*dbh).error_mode != php::PDO_ERRMODE_EXCEPTION {
        engine::zend_clear_exception()?;
    }
    Ok(())
}
unsafe fn error_info(dbh: *mut pdo_dbh_t, stmt: *mut pdo_stmt_t, info: *mut zval) -> Result<()> {
    let saved = diagnostics(dbh, stmt);
    if kind(&raw const (*saved).message) == php::IS_STRING && (*saved).state == *state(dbh, stmt) {
        engine::add_next_index_long(info, (*saved).code)?;
        engine::add_next_index_str(info, copy(&raw const (*saved).message).value.str_)?;
    }
    Ok(())
}
unsafe fn exec(dbh: *mut pdo_dbh_t, sql: *const zend_string) -> Result<i64> {
    discard(&raw mut (*diagnostics(dbh, null_mut())).message)?;
    let Some(mut result) = bridge(dbh, c"exec", sql, null_mut())? else {
        sql_error(dbh, null_mut())?;
        return Ok(-1);
    };
    if kind(&result) != php::IS_LONG {
        discard(&mut result)?;
        error(c"Invalid affected row count from the Atom database.")?;
        return Ok(-1);
    }
    Ok(result.value.lval)
}
unsafe fn owns(dbh: *mut pdo_dbh_t) -> Result<bool> {
    if !(*dbh).in_txn() {
        return Ok(false);
    }
    let Some(mut result) = bridge(dbh, c"owns", null_mut(), null_mut())? else {
        return Ok(false);
    };
    let answer = kind(&result) == php::IS_TRUE;
    discard(&mut result)?;
    Ok(answer)
}
unsafe fn last_id(dbh: *mut pdo_dbh_t) -> Result<*mut zend_string> {
    let Some(mut result) = bridge(dbh, c"lastId", null_mut(), null_mut())? else {
        return Ok(null_mut());
    };
    if kind(&result) != php::IS_STRING {
        discard(&mut result)?;
        error(c"Invalid last insert ID from the Atom database.")?;
        return Ok(null_mut());
    }
    Ok(result.value.str_)
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_exec(dbh: *mut pdo_dbh_t, sql: *const zend_string) -> i64 {
    exec(dbh, sql).unwrap_or(-1)
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_owns(dbh: *mut pdo_dbh_t) -> bool {
    owns(dbh).unwrap_or(false)
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_last_id(dbh: *mut pdo_dbh_t) -> *mut zend_string {
    last_id(dbh).unwrap_or(null_mut())
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_error_info(
    dbh: *mut pdo_dbh_t,
    stmt: *mut pdo_stmt_t,
    info: *mut zval,
) {
    let _ = error_info(dbh, stmt, info);
}
