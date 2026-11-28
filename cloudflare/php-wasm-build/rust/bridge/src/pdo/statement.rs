use super::{
    bridge, check, engine, error, php, sql_error, value::*, Diagnostics, Result, STATEMENTS,
};
use core::mem::size_of;
use core::ptr::{addr_of, null_mut};
use php::{pdo_dbh_t, pdo_stmt_t, zend_string, zval};

struct Statement {
    rows: zval,
    columns: zval,
    diagnostics: Diagnostics,
    next_row: u32,
    statement: *mut pdo_stmt_t,
    previous: *mut Statement,
    next: *mut Statement,
}
// Non-owning links let NativePdo::leave close each current cursor without
// retaining a PDOStatement beyond its normal Zend lifetime.
static mut RESULTS: *mut Statement = null_mut();
unsafe fn data(stmt: *mut pdo_stmt_t) -> *mut Statement {
    (*stmt).driver_data.cast()
}
pub(super) unsafe fn diagnostics(stmt: *mut pdo_stmt_t) -> *mut Diagnostics {
    &raw mut (*data(stmt)).diagnostics
}
unsafe fn track(stmt: *mut pdo_stmt_t) {
    let s = data(stmt);
    if (*s).statement.is_null() {
        (*s).statement = stmt;
        (*s).next = RESULTS;
        if !RESULTS.is_null() {
            (*RESULTS).previous = s;
        }
        RESULTS = s;
    }
}
unsafe fn close_cursor(stmt: *mut pdo_stmt_t) -> Result<i32> {
    let s = data(stmt);
    if !(*s).statement.is_null() {
        if (*s).previous.is_null() {
            RESULTS = (*s).next;
        } else {
            (*(*s).previous).next = (*s).next;
        }
        if !(*s).next.is_null() {
            (*(*s).next).previous = (*s).previous;
        }
        (*s).previous = null_mut();
        (*s).next = null_mut();
        (*s).statement = null_mut();
    }
    (*s).next_row = 0;
    discard(&raw mut (*s).rows)?;
    Ok(1)
}
unsafe fn destroy(stmt: *mut pdo_stmt_t) -> Result<i32> {
    let s = data(stmt);
    close_cursor(stmt)?;
    discard(&raw mut (*s).columns)?;
    discard(&raw mut (*s).diagnostics.message)?;
    (*stmt).driver_data = null_mut();
    engine::_efree(s.cast())?;
    Ok(1)
}
unsafe fn close_all() -> Result<()> {
    while !RESULTS.is_null() {
        close_cursor((*RESULTS).statement)?;
    }
    Ok(())
}
unsafe fn prepare(dbh: *mut pdo_dbh_t, stmt: *mut pdo_stmt_t, options: *mut zval) -> Result<bool> {
    if !check(dbh, c"check")? {
        return Ok(false);
    }
    if !options.is_null() {
        let cursor = index(options, php::PDO_ATTR_CURSOR.into());
        if !cursor.is_null()
            && engine::zval_get_long_func(cursor, false)? != php::PDO_CURSOR_FWDONLY as i64
        {
            engine::pdo_raise_impl_error(
                dbh,
                null_mut(),
                c"IM001".as_ptr().cast_mut(),
                c"The Atoms cursor is forward only".as_ptr(),
            )?;
            return Ok(false);
        }
    }
    (*stmt).driver_data = engine::_ecalloc(1, size_of::<Statement>())?;
    (*stmt).methods = addr_of!(STATEMENTS);
    (*stmt).set_supports_placeholders(php::PDO_PLACEHOLDER_POSITIONAL | php::PDO_PLACEHOLDER_NAMED);
    Ok(true)
}
struct Binding {
    value: zval,
    name: zval,
    position: u64,
    ty: php::pdo_param_type,
}

// Conversion can run arbitrary PHP. Retain the values and keys before
// converting the first parameter. Keeping a reference zval intact preserves
// bindParam's current value until it is needed by this execution.
unsafe fn bindings(stmt: *mut pdo_stmt_t, args: *mut zval) -> Result<bool> {
    (*args).value.arr = engine::_zend_new_array(0)?;
    (*args).u1.type_info = php::IS_ARRAY_EX;
    let table = (*stmt).bound_params;
    if table.is_null() || (*table).nNumOfElements == 0 {
        return Ok(true);
    }
    let count = (*table).nNumOfElements as usize;
    let saved = engine::_ecalloc(count, size_of::<Binding>())?.cast::<Binding>();
    let mut pos = 0;
    php::zend_hash_internal_pointer_reset_ex(table, &mut pos);
    for i in 0..count {
        let item = php::zend_hash_get_current_data_ex(table, &mut pos);
        let param = (*item).value.ptr.cast::<php::pdo_bound_param_data>();
        let dest = saved.add(i);
        (*dest).value = copy(&raw const (*param).parameter);
        if !(*param).name.is_null() {
            (*dest).name = copy(&string_value((*param).name));
        }
        (*dest).position = (*param).paramno as u64;
        (*dest).ty = (*param).param_type & !php::PDO_PARAM_FLAGS;
        php::zend_hash_move_forward_ex(table, &mut pos);
    }
    let mut ok = true;
    for i in 0..count {
        if ok {
            ok = bind_one(stmt, args, saved.add(i))?;
        }
        discard(&raw mut (*saved.add(i)).value)?;
        discard(&raw mut (*saved.add(i)).name)?;
    }
    engine::_efree(saved.cast())?;
    Ok(ok)
}

unsafe fn bind_one(stmt: *mut pdo_stmt_t, args: *mut zval, param: *mut Binding) -> Result<bool> {
    let source = deref(&raw mut (*param).value);
    let mut value = if kind(source) == php::IS_NULL {
        scalar(php::IS_NULL, 0)
    } else {
        match (*param).ty {
            php::PDO_PARAM_NULL => scalar(php::IS_NULL, 0),
            php::PDO_PARAM_BOOL => scalar(
                if engine::zend_is_true(source)? != 0 {
                    php::IS_TRUE
                } else {
                    php::IS_FALSE
                },
                0,
            ),
            php::PDO_PARAM_INT => scalar(php::IS_LONG, engine::zval_get_long_func(source, false)?),
            php::PDO_PARAM_STR => {
                let string = engine::zval_try_get_string_func(source)?;
                if string.is_null() {
                    return Ok(false);
                }
                string_value(string)
            }
            _ => {
                engine::pdo_raise_impl_error(
                    (*stmt).dbh,
                    stmt,
                    c"IM001".as_ptr().cast_mut(),
                    c"Unsupported Atoms parameter type".as_ptr(),
                )?;
                return Ok(false);
            }
        }
    };
    if kind(&raw const (*param).name) == php::IS_STRING {
        engine::zend_hash_update((*args).value.arr, (*param).name.value.str_, &mut value)?;
    } else {
        engine::zend_hash_index_update((*args).value.arr, (*param).position, &mut value)?;
    }
    Ok(true)
}
unsafe fn execute(stmt: *mut pdo_stmt_t) -> Result<i32> {
    let s = data(stmt);
    close_cursor(stmt)?;
    discard(&raw mut (*s).diagnostics.message)?;
    let mut args = undefined();
    if !bindings(stmt, &mut args)? {
        discard(&mut args)?;
        return Ok(0);
    }
    let result = bridge((*stmt).dbh, c"execute", (*stmt).query_string, &mut args)?;
    let Some(mut result) = result else {
        sql_error((*stmt).dbh, stmt)?;
        discard(&mut args)?;
        return Ok(0);
    };
    discard(&mut args)?;
    let (rows, cols, written) = (
        key(&result, c"rows"),
        key(&result, c"columns"),
        key(&result, c"rows_written"),
    );
    if rows.is_null()
        || kind(rows) != php::IS_ARRAY
        || cols.is_null()
        || kind(cols) != php::IS_ARRAY
        || written.is_null()
        || kind(written) != php::IS_LONG
    {
        discard(&mut result)?;
        error(c"Invalid result from the Atom database.")?;
        return Ok(0);
    }
    // A binding conversion or PHP bridge hook may have opened a nested cursor.
    close_cursor(stmt)?;
    (*s).rows = copy(rows);
    discard(&raw mut (*s).columns)?;
    (*s).columns = copy(cols);
    engine::php_pdo_stmt_set_column_count(stmt, 0)?;
    engine::php_pdo_stmt_set_column_count(stmt, (*(*cols).value.arr).nNumOfElements as i32)?;
    (*stmt).set_executed(0);
    (*stmt).row_count = (*written).value.lval;
    track(stmt);
    discard(&mut result)?;
    Ok(1)
}
unsafe fn fetch(stmt: *mut pdo_stmt_t, orientation: php::pdo_fetch_orientation) -> Result<i32> {
    let s = data(stmt);
    if (*s).statement.is_null()
        || !check((*stmt).dbh, c"check")?
        || kind(&raw const (*s).rows) != php::IS_ARRAY
    {
        return Ok(0);
    }
    if orientation != php::PDO_FETCH_ORI_NEXT {
        engine::pdo_raise_impl_error(
            (*stmt).dbh,
            stmt,
            c"IM001".as_ptr().cast_mut(),
            c"The Atoms cursor is forward only".as_ptr(),
        )?;
        return Ok(0);
    }
    if (*s).next_row >= (*(*s).rows.value.arr).nNumOfElements {
        return Ok(0);
    }
    (*s).next_row += 1;
    Ok(1)
}
unsafe fn column_name(s: *mut Statement, col: i32) -> *mut zend_string {
    let name = index(&raw const (*s).columns, col as u64);
    if name.is_null() || kind(name) != php::IS_STRING {
        null_mut()
    } else {
        (*name).value.str_
    }
}
unsafe fn describe(stmt: *mut pdo_stmt_t, col: i32) -> Result<i32> {
    let name = column_name(data(stmt), col);
    if name.is_null() {
        error(c"Invalid column name from the Atom database.")?;
        return Ok(0);
    }
    let description = (*stmt).columns.add(col as usize);
    (*description).name =
        engine::zend_string_concat2(addr_of!((*name).val).cast(), (*name).len, c"".as_ptr(), 0)?;
    (*description).maxlen = usize::MAX;
    Ok(1)
}
unsafe fn column(stmt: *mut pdo_stmt_t, col: i32, output: *mut zval) -> Result<i32> {
    let s = data(stmt);
    if (*s).next_row == 0
        || !check((*stmt).dbh, c"check")?
        || kind(&raw const (*s).rows) != php::IS_ARRAY
    {
        return Ok(0);
    }
    let row = index(&raw const (*s).rows, u64::from((*s).next_row - 1));
    let name = column_name(s, col);
    let mut value = null_mut();
    if !row.is_null() && kind(row) == php::IS_ARRAY && !name.is_null() {
        let text = bytes(name);
        let mut numeric = 0;
        // Zend's symbol table converts canonical decimal column names into integer keys.
        let digits = text.strip_prefix(b"-").unwrap_or(text);
        let looks_numeric = digits.first().is_some_and(u8::is_ascii_digit);
        value = if looks_numeric
            && php::_zend_handle_numeric_str_ex(text.as_ptr().cast(), text.len(), &mut numeric)
        {
            php::zend_hash_index_find((*row).value.arr, numeric)
        } else {
            php::zend_hash_find((*row).value.arr, name)
        };
    }
    if value.is_null() {
        error(c"Missing column in the Atom database result.")?;
        return Ok(0);
    }
    let result = copy(value);
    (*output).value = result.value;
    (*output).u1 = result.u1;
    Ok(1)
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_close_cursors(frame: *mut php::zend_execute_data) {
    if (*frame).This.u2.num_args != 0 {
        let _ = engine::zend_wrong_parameters_none_error();
    } else {
        let _ = close_all();
    }
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_rshutdown() -> i32 {
    close_all().map_or(-1, |_| 0)
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_destroy_stmt(stmt: *mut pdo_stmt_t) -> i32 {
    destroy(stmt).unwrap_or(0)
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_close_cursor(stmt: *mut pdo_stmt_t) -> i32 {
    close_cursor(stmt).unwrap_or(0)
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_prepare(
    dbh: *mut pdo_dbh_t,
    stmt: *mut pdo_stmt_t,
    options: *mut zval,
) -> bool {
    prepare(dbh, stmt, options).unwrap_or(false)
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_execute(stmt: *mut pdo_stmt_t) -> i32 {
    execute(stmt).unwrap_or(0)
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_fetch(
    stmt: *mut pdo_stmt_t,
    orientation: php::pdo_fetch_orientation,
) -> i32 {
    fetch(stmt, orientation).unwrap_or(0)
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_describe(stmt: *mut pdo_stmt_t, col: i32) -> i32 {
    describe(stmt, col).unwrap_or(0)
}
#[no_mangle]
pub unsafe extern "C" fn atoms_rs_column(
    stmt: *mut pdo_stmt_t,
    col: i32,
    output: *mut zval,
) -> i32 {
    column(stmt, col, output).unwrap_or(0)
}
