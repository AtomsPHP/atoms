#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "Zend/zend_exceptions.h"
#include "ext/pdo/php_pdo_driver.h"
#include "php_pdo_atoms.h"

/* The same source is compiled against each interpreter's own Zend/PDO ABI. */
#ifdef __EMSCRIPTEN__
_Static_assert(sizeof(void *) == 4 && sizeof(zend_long) == 8,
    "pdo_atoms requires the Atoms wasm32 build with 64-bit PHP integers");
#endif

typedef struct {
    pdo_error_type state;
    zend_long code;
    zend_string *message;
} atoms_diagnostics;

static void atoms_clear_diagnostics(atoms_diagnostics *error)
{
    if (error->message) {
        zend_string_release(error->message);
        error->message = NULL;
    }
}

typedef struct {
    zend_long token;
    atoms_diagnostics error;
} atoms_handle;

typedef struct atoms_statement {
    zval rows;
    zval columns;
    atoms_diagnostics error;
    uint32_t next_row;
    pdo_stmt_t *statement;
    struct atoms_statement *previous;
    struct atoms_statement *next;
} atoms_statement;

ZEND_BEGIN_MODULE_GLOBALS(pdo_atoms)
    atoms_statement *results;
ZEND_END_MODULE_GLOBALS(pdo_atoms)
ZEND_DECLARE_MODULE_GLOBALS(pdo_atoms)
#define ATOMS_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(pdo_atoms, v)

#if defined(ZTS) && defined(COMPILE_DL_PDO_ATOMS)
ZEND_TSRMLS_CACHE_DEFINE()
#endif

/* These are non-owning links. Destroying a statement immediately removes it,
 * even when no PHP reference survives the expression that ran the query. */
static void atoms_track_result(pdo_stmt_t *stmt)
{
    atoms_statement *s = stmt->driver_data;
    if (!s->statement) {
        s->statement = stmt;
        s->next = ATOMS_G(results);
        if (s->next) {
            s->next->previous = s;
        }
        ATOMS_G(results) = s;
    }
}

static void atoms_untrack_result(atoms_statement *s)
{
    if (!s->statement) {
        return;
    }
    if (s->previous) {
        s->previous->next = s->next;
    } else {
        ATOMS_G(results) = s->next;
    }
    if (s->next) {
        s->next->previous = s->previous;
    }
    s->previous = s->next = NULL;
    s->statement = NULL;
}

static void atoms_error(const char *message)
{
    zend_throw_exception(php_pdo_get_exception(), message, 0);
}

/* These five suspending functions have external linkage and are not inlined.
 * The owned build selects their C symbol names for Asyncify instrumentation. */
zend_never_inline bool atoms_bridge_call(pdo_dbh_t *dbh, const char *op,
    const zend_string *sql, zval *bindings, zval *result)
{
    zend_function *bridge = zend_hash_str_find_ptr(EG(function_table),
        ZEND_STRL("atoms\\cf\\native_pdo"));
    zval args[4];
    uint32_t argc = 2;
    ZVAL_UNDEF(result);
    if (!bridge) {
        atoms_error("PDO('atoms:') requires an active Atom.");
        return false;
    }
    ZVAL_STRING(&args[0], op);
    ZVAL_LONG(&args[1], dbh->driver_data ? ((atoms_handle *)dbh->driver_data)->token : 0);
    if (sql) {
        ZVAL_STR(&args[2], (zend_string *)sql);
        argc = 3;
        if (bindings) {
            ZVAL_COPY_VALUE(&args[3], bindings);
            argc = 4;
        }
    }
    /* PDO can ask whether it should roll back while Zend unwinds a frame.
     * zend_call_function skips userland when an exception is already pending.
     * Preserve that exception across the cleanup call, including its throw site. */
    zend_object *pending = EG(exception);
    const zend_op *throw_site = EG(opline_before_exception);
    EG(exception) = NULL;
    zend_call_known_function(bridge, NULL, NULL, result, argc, args, NULL);
    bool ok = EG(exception) == NULL;
    if (pending) {
        if (EG(exception)) {
            zend_exception_set_previous(pending, EG(exception));
        }
        EG(exception) = pending;
        EG(opline_before_exception) = throw_site;
    }
    zval_ptr_dtor(&args[0]);
    if (!ok) {
        zval_ptr_dtor(result);
        ZVAL_UNDEF(result);
        return false;
    }
    return true;
}

static bool atoms_check(pdo_dbh_t *dbh)
{
    zval result;
    if (!atoms_bridge_call(dbh, "check", NULL, NULL, &result)) {
        return false;
    }
    zval_ptr_dtor(&result);
    return true;
}

zend_never_inline bool atoms_transaction(pdo_dbh_t *dbh, const char *op)
{
    zval result;
    if (!atoms_bridge_call(dbh, op, NULL, NULL, &result)) {
        return false;
    }
    zval_ptr_dtor(&result);
    return true;
}

zend_never_inline bool atoms_begin(pdo_dbh_t *dbh)
{
    return atoms_transaction(dbh, "begin");
}

zend_never_inline bool atoms_commit(pdo_dbh_t *dbh)
{
    return atoms_transaction(dbh, "commit");
}

zend_never_inline bool atoms_rollback(pdo_dbh_t *dbh)
{
    return atoms_transaction(dbh, "rollback");
}

static bool atoms_in_transaction(pdo_dbh_t *dbh)
{
    /* PDO sets in_txn only on the handle that began the transaction. The
     * bridge additionally checks that its owning window is still active. */
    zval result;
    if (!dbh->in_txn || !atoms_bridge_call(dbh, "owns", NULL, NULL, &result)) {
        return false;
    }
    bool owns = Z_TYPE(result) == IS_TRUE;
    zval_ptr_dtor(&result);
    return owns;
}

static int atoms_close_cursor(pdo_stmt_t *stmt)
{
    atoms_statement *s = stmt->driver_data;
    atoms_untrack_result(s);
    zval_ptr_dtor(&s->rows);
    ZVAL_UNDEF(&s->rows);
    s->next_row = 0;
    /* A PHP callback can close a cursor during PDO object hydration. Keep its
     * column descriptions and the native names backing the describer until
     * the next execution or statement destruction. */
    return 1;
}

static int atoms_free_statement(pdo_stmt_t *stmt)
{
    atoms_statement *s = stmt->driver_data;
    atoms_close_cursor(stmt);
    zval_ptr_dtor(&s->columns);
    atoms_clear_diagnostics(&s->error);
    efree(s);
    stmt->driver_data = NULL;
    return 1;
}

static void atoms_close_results(void)
{
    while (ATOMS_G(results)) {
        atoms_close_cursor(ATOMS_G(results)->statement);
    }
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_close_cursors, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

static PHP_FUNCTION(native_pdo_close_cursors)
{
    ZEND_PARSE_PARAMETERS_NONE();
    atoms_close_results();
}

static const zend_function_entry atoms_functions[] = {
    ZEND_NS_FE("Atoms\\Cf", native_pdo_close_cursors, arginfo_close_cursors)
    ZEND_FE_END
};

/* PDO keeps bindParam references alive. Resolve and coerce them for each
 * execution, preserving zend_long width all the way to the guest SQL bridge. */
static bool atoms_bindings(pdo_stmt_t *stmt, zval *bindings)
{
    array_init(bindings);
    if (!stmt->bound_params) {
        return true;
    }
    struct pdo_bound_param_data *param;
    ZEND_HASH_FOREACH_PTR(stmt->bound_params, param) {
        zval value, *source = &param->parameter;
        ZVAL_DEREF(source);
        enum pdo_param_type type = PDO_PARAM_TYPE(param->param_type);
        if (Z_TYPE_P(source) == IS_NULL || type == PDO_PARAM_NULL) {
            ZVAL_NULL(&value);
        } else if (type == PDO_PARAM_BOOL) {
            ZVAL_BOOL(&value, zend_is_true(source));
        } else if (type == PDO_PARAM_INT) {
            ZVAL_LONG(&value, zval_get_long(source));
        } else if (type == PDO_PARAM_STR) {
            zend_string *string = zval_try_get_string(source);
            if (!string) {
                return false;
            }
            ZVAL_STR(&value, string);
        } else {
            pdo_raise_impl_error(stmt->dbh, stmt, "IM001", "Unsupported Atoms parameter type");
            return false;
        }
        if (param->name) {
            zend_hash_update(Z_ARRVAL_P(bindings), param->name, &value);
        } else {
            zend_hash_index_update(Z_ARRVAL_P(bindings), param->paramno, &value);
        }
    } ZEND_HASH_FOREACH_END();
    return true;
}

static atoms_diagnostics *atoms_diagnostics_for(pdo_dbh_t *dbh, pdo_stmt_t *stmt)
{
    return stmt ? &((atoms_statement *)stmt->driver_data)->error : &((atoms_handle *)dbh->driver_data)->error;
}

/* PDO core owns ERRMODE. Supply the SQLSTATE and native diagnostics for the
 * handle that ran the statement, then let core return false, warn or throw.
 * A guest context/ownership error carries no SQL diagnostics and propagates. */
static void atoms_sql_error(pdo_dbh_t *dbh, pdo_stmt_t *stmt)
{
    zend_object *exception = EG(exception);
    zend_class_entry *pdo_exception = php_pdo_get_exception();
    if (!exception || !instanceof_function(exception->ce, pdo_exception)) {
        return;
    }
    zval rv;
    zval *info = zend_read_property(pdo_exception, exception, ZEND_STRL("errorInfo"), true, &rv);
    if (Z_TYPE_P(info) != IS_ARRAY) {
        return;
    }
    zval *state = zend_hash_index_find(Z_ARRVAL_P(info), 0);
    zval *code = zend_hash_index_find(Z_ARRVAL_P(info), 1);
    zval *message = zend_hash_index_find(Z_ARRVAL_P(info), 2);
    if (!state || Z_TYPE_P(state) != IS_STRING || Z_STRLEN_P(state) != sizeof(pdo_error_type) - 1 ||
        !code || Z_TYPE_P(code) != IS_LONG || !message || Z_TYPE_P(message) != IS_STRING) {
        return;
    }
    atoms_diagnostics *saved = atoms_diagnostics_for(dbh, stmt);
    memcpy(saved->state, Z_STRVAL_P(state), sizeof(saved->state));
    memcpy(stmt ? stmt->error_code : dbh->error_code, saved->state, sizeof(saved->state));
    saved->code = Z_LVAL_P(code);
    saved->message = zend_string_copy(Z_STR_P(message));
    /* Preserve the bridge exception and its additional details in EXCEPTION mode. */
    if (dbh->error_mode != PDO_ERRMODE_EXCEPTION) {
        zend_clear_exception();
    }
}

static void atoms_fetch_error(pdo_dbh_t *dbh, pdo_stmt_t *stmt, zval *info)
{
    atoms_diagnostics *saved = atoms_diagnostics_for(dbh, stmt);
    if (saved->message && memcmp(saved->state, stmt ? stmt->error_code : dbh->error_code, sizeof(saved->state)) == 0) {
        add_next_index_long(info, saved->code);
        add_next_index_str(info, zend_string_copy(saved->message));
    }
}

static int atoms_execute(pdo_stmt_t *stmt)
{
    atoms_statement *s = stmt->driver_data;
    zval bindings, result;
    atoms_close_cursor(stmt);
    atoms_clear_diagnostics(&s->error);
    if (!atoms_bindings(stmt, &bindings)) {
        zval_ptr_dtor(&bindings);
        return 0;
    }
    if (!atoms_bridge_call(stmt->dbh, "execute", stmt->query_string, &bindings, &result)) {
        atoms_sql_error(stmt->dbh, stmt);
        zval_ptr_dtor(&bindings);
        return 0;
    }
    zval_ptr_dtor(&bindings);
    HashTable *reply = Z_TYPE(result) == IS_ARRAY ? Z_ARRVAL(result) : NULL;
    zval *rows = reply ? zend_hash_str_find(reply, ZEND_STRL("rows")) : NULL;
    zval *columns = reply ? zend_hash_str_find(reply, ZEND_STRL("columns")) : NULL;
    zval *written = reply ? zend_hash_str_find(reply, ZEND_STRL("rows_written")) : NULL;
    if (!rows || Z_TYPE_P(rows) != IS_ARRAY || !columns || Z_TYPE_P(columns) != IS_ARRAY ||
        !written || Z_TYPE_P(written) != IS_LONG) {
        zval_ptr_dtor(&result);
        atoms_error("Invalid result from the Atom database.");
        return 0;
    }
    ZVAL_COPY(&s->rows, rows);
    zval_ptr_dtor(&s->columns);
    ZVAL_COPY(&s->columns, columns);
    /* SELECT * can see renamed columns after a schema change. PDO would
     * otherwise keep its previous descriptions when the count is unchanged. */
    php_pdo_stmt_set_column_count(stmt, 0);
    php_pdo_stmt_set_column_count(stmt, zend_hash_num_elements(Z_ARRVAL_P(columns)));
    /* Have PDO describe the new columns immediately, including for a PDORow
     * that still references this statement before its first fresh fetch. */
    stmt->executed = 0;
    stmt->row_count = Z_LVAL_P(written);
    atoms_track_result(stmt);
    zval_ptr_dtor(&result);
    return 1;
}

static int atoms_fetch(pdo_stmt_t *stmt, enum pdo_fetch_orientation orientation, zend_long offset)
{
    atoms_statement *s = stmt->driver_data;
    if (!s->statement || !atoms_check(stmt->dbh) || Z_ISUNDEF(s->rows)) {
        return 0;
    }
    if (orientation != PDO_FETCH_ORI_NEXT) {
        pdo_raise_impl_error(stmt->dbh, stmt, "IM001", "The Atoms cursor is forward only");
        return 0;
    }
    if (s->next_row >= zend_hash_num_elements(Z_ARRVAL(s->rows))) {
        return 0;
    }
    s->next_row++;
    return 1;
}

static zend_string *atoms_column_name(atoms_statement *s, int colno)
{
    zval *name = zend_hash_index_find(Z_ARRVAL(s->columns), colno);
    return name && Z_TYPE_P(name) == IS_STRING ? Z_STR_P(name) : NULL;
}

static int atoms_describe(pdo_stmt_t *stmt, int colno)
{
    zend_string *name = atoms_column_name(stmt->driver_data, colno);
    if (!name) {
        atoms_error("Invalid column name from the Atom database.");
        return 0;
    }
    /* PHP 8.3 may uppercase the description in place for ATTR_CASE. Keep
     * the bridge's column name intact for lookup in the wire's row map. */
    stmt->columns[colno].name = zend_string_init(ZSTR_VAL(name), ZSTR_LEN(name), false);
    stmt->columns[colno].maxlen = SIZE_MAX;
    return 1;
}

static int atoms_get_column(pdo_stmt_t *stmt, int colno, zval *result, enum pdo_param_type *type)
{
    atoms_statement *s = stmt->driver_data;
    /* FETCH_LAZY can read a column long after its originating fetch(). */
    if (!s->next_row || !atoms_check(stmt->dbh) || Z_ISUNDEF(s->rows)) {
        return 0;
    }
    zval *row = zend_hash_index_find(Z_ARRVAL(s->rows), s->next_row - 1);
    zend_string *name = atoms_column_name(s, colno);
    zval *value = row && Z_TYPE_P(row) == IS_ARRAY && name ? zend_symtable_find(Z_ARRVAL_P(row), name) : NULL;
    if (!value) {
        atoms_error("Missing column in the Atom database result.");
        return 0;
    }
    ZVAL_COPY(result, value);
    return 1;
}

static const struct pdo_stmt_methods atoms_statement_methods = {
    .dtor = atoms_free_statement,
    .executer = atoms_execute,
    .fetcher = atoms_fetch,
    .describer = atoms_describe,
    .get_col = atoms_get_column,
    .cursor_closer = atoms_close_cursor,
};

static bool atoms_prepare(pdo_dbh_t *dbh, zend_string *sql, pdo_stmt_t *stmt, zval *options)
{
    if (!atoms_check(dbh)) {
        return false;
    }
    if (pdo_attr_lval(options, PDO_ATTR_CURSOR, PDO_CURSOR_FWDONLY) != PDO_CURSOR_FWDONLY) {
        pdo_raise_impl_error(dbh, NULL, "IM001", "The Atoms cursor is forward only");
        return false;
    }
    stmt->driver_data = ecalloc(1, sizeof(atoms_statement));
    stmt->methods = &atoms_statement_methods;
    stmt->supports_placeholders = PDO_PLACEHOLDER_POSITIONAL | PDO_PLACEHOLDER_NAMED;
    return true;
}

static zend_long atoms_exec(pdo_dbh_t *dbh, const zend_string *sql)
{
    zval result;
    atoms_handle *handle = dbh->driver_data;
    atoms_clear_diagnostics(&handle->error);
    if (!atoms_bridge_call(dbh, "exec", sql, NULL, &result)) {
        atoms_sql_error(dbh, NULL);
        return -1;
    }
    if (Z_TYPE(result) != IS_LONG) {
        zval_ptr_dtor(&result);
        atoms_error("Invalid affected row count from the Atom database.");
        return -1;
    }
    return Z_LVAL(result);
}

/* SQLite string literals are delimited with apostrophes. The PDO type
 * argument does not change the representation of the supplied string. */
static zend_string *atoms_quote(pdo_dbh_t *dbh, const zend_string *input, enum pdo_param_type type)
{
    size_t apostrophes = 0;
    for (size_t i = 0; i < ZSTR_LEN(input); ++i) {
        if (ZSTR_VAL(input)[i] == '\0') {
            atoms_error("PDO::quote() refuses a value containing a NUL byte.");
            return NULL;
        }
        if (ZSTR_VAL(input)[i] == '\'') {
            ++apostrophes;
        }
    }
    zend_string *quoted = zend_string_safe_alloc(1, ZSTR_LEN(input), apostrophes + 2, 0);
    char *out = ZSTR_VAL(quoted);
    *out++ = '\'';
    for (size_t i = 0; i < ZSTR_LEN(input); ++i) {
        char ch = ZSTR_VAL(input)[i];
        if (ch == '\'') {
            *out++ = ch;
        }
        *out++ = ch;
    }
    *out++ = '\'';
    *out = '\0';
    atoms_clear_diagnostics(&((atoms_handle *)dbh->driver_data)->error);
    return quoted;
}

static zend_string *atoms_last_id(pdo_dbh_t *dbh, const zend_string *name)
{
    zval result;
    if (!atoms_bridge_call(dbh, "lastId", NULL, NULL, &result)) {
        return NULL;
    }
    if (Z_TYPE(result) != IS_STRING) {
        zval_ptr_dtor(&result);
        atoms_error("Invalid last insert ID from the Atom database.");
        return NULL;
    }
    return Z_STR(result);
}

static void atoms_close(pdo_dbh_t *dbh)
{
    atoms_handle *handle = dbh->driver_data;
    if (handle) {
        dbh->driver_data = NULL;
        atoms_clear_diagnostics(&handle->error);
        efree(handle);
    }
}

static const struct pdo_dbh_methods atoms_methods = {
    .closer = atoms_close,
    .preparer = atoms_prepare,
    .doer = atoms_exec,
    .quoter = atoms_quote,
    .begin = atoms_begin,
    .commit = atoms_commit,
    .rollback = atoms_rollback,
    .last_id = atoms_last_id,
    .fetch_err = atoms_fetch_error,
    .in_transaction = atoms_in_transaction,
};

static bool atoms_bare_dsn(void)
{
    /* PDO passes the factory NUL-terminated copies and expands DSN aliases.
     * Validate the original arguments to PDO::__construct()/connect() so
     * the accepted DSN and credentials really are exact byte strings. */
    zend_execute_data *call = EG(current_execute_data);
    if (!call || ZEND_CALL_NUM_ARGS(call) < 1) {
        return false;
    }
    zval *dsn = ZEND_CALL_ARG(call, 1);
    ZVAL_DEREF(dsn);
    if (Z_TYPE_P(dsn) != IS_STRING || !zend_string_equals_literal(Z_STR_P(dsn), "atoms:")) {
        return false;
    }
    for (uint32_t i = 2; i <= 3 && i <= ZEND_CALL_NUM_ARGS(call); i++) {
        zval *credential = ZEND_CALL_ARG(call, i);
        ZVAL_DEREF(credential);
        if (Z_ISUNDEF_P(credential) || Z_TYPE_P(credential) == IS_NULL) {
            continue;
        }
        if (Z_TYPE_P(credential) != IS_STRING || Z_STRLEN_P(credential)) {
            return false;
        }
    }
    return true;
}

static int atoms_open(pdo_dbh_t *dbh, zval *options)
{
    if (!atoms_bare_dsn()) {
        atoms_error("Use the bare atoms: DSN without credentials.");
        return 0;
    }
    if (dbh->is_persistent) {
        atoms_error("Persistent Atom database connections are unsupported.");
        return 0;
    }
    zval result;
    if (!atoms_bridge_call(dbh, "open", NULL, NULL, &result)) {
        return 0;
    }
    if (Z_TYPE(result) != IS_LONG || Z_LVAL(result) <= 0) {
        zval_ptr_dtor(&result);
        atoms_error("Invalid Atom database handle.");
        return 0;
    }
    atoms_handle *handle = ecalloc(1, sizeof(atoms_handle));
    handle->token = Z_LVAL(result);
    dbh->driver_data = handle;
    dbh->methods = &atoms_methods;
    dbh->alloc_own_columns = true;
    return 1;
}

static const pdo_driver_t atoms_driver = { PDO_DRIVER_HEADER(atoms), atoms_open };

PHP_MINIT_FUNCTION(pdo_atoms)
{
    return php_pdo_register_driver(&atoms_driver);
}

PHP_MSHUTDOWN_FUNCTION(pdo_atoms)
{
    php_pdo_unregister_driver(&atoms_driver);
    return SUCCESS;
}

static const zend_module_dep atoms_deps[] = { ZEND_MOD_REQUIRED("pdo") ZEND_MOD_END };

static PHP_GINIT_FUNCTION(pdo_atoms)
{
    pdo_atoms_globals->results = NULL;
}

static PHP_RINIT_FUNCTION(pdo_atoms)
{
#if defined(ZTS) && defined(COMPILE_DL_PDO_ATOMS)
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
    return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(pdo_atoms)
{
    atoms_close_results();
    return SUCCESS;
}

zend_module_entry pdo_atoms_module_entry = {
    STANDARD_MODULE_HEADER_EX, NULL, atoms_deps,
    "pdo_atoms", atoms_functions, PHP_MINIT(pdo_atoms), PHP_MSHUTDOWN(pdo_atoms),
    PHP_RINIT(pdo_atoms), PHP_RSHUTDOWN(pdo_atoms), NULL, PHP_VERSION,
    PHP_MODULE_GLOBALS(pdo_atoms), PHP_GINIT(pdo_atoms), NULL, NULL, STANDARD_MODULE_PROPERTIES_EX
};

#ifdef COMPILE_DL_PDO_ATOMS
ZEND_GET_MODULE(pdo_atoms)
#endif
