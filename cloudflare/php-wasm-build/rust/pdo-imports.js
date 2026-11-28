// PHP invokes the PDO method table through these imports. Zend can call PHP
// from a conversion, a destructor, or the database bridge. The innermost JS
// leaf catches a bailout and returns to Rust. Only the outer entry rethrows.
addToLibrary({
  atoms_register_builtin_pdo__deps: ['php_register_internal_extensions', 'zend_register_internal_module', 'atoms_pdo_module'],
  atoms_register_builtin_pdo: () => {
    if (_php_register_internal_extensions() !== 0) return -1;
    return _zend_register_internal_module(_atoms_pdo_module()) ? 0 : -1;
  },
  atoms_pdo_minit__deps: ['php_pdo_register_driver', 'atoms_pdo_driver'],
  atoms_pdo_minit: () => _php_pdo_register_driver(_atoms_pdo_driver()),
  atoms_pdo_mshutdown__deps: ['php_pdo_unregister_driver', 'atoms_pdo_driver'],
  atoms_pdo_mshutdown: () => { _php_pdo_unregister_driver(_atoms_pdo_driver()); return 0; },

  $atomsPdo__deps: ['$Asyncify'],
  $atomsPdo: {
    depth: 0,
    frames: [],
    enter(fn, ...args) {
      const depth = atomsPdo.depth++;
      const frame = Asyncify.state === Asyncify.State.Rewinding ? atomsPdo.frames[depth] : (atomsPdo.frames[depth] = {});
      try {
        const result = fn(...args);
        if (frame.failed) throw frame.error;
        return result;
      } finally {
        atomsPdo.depth--;
        if (Asyncify.state !== Asyncify.State.Unwinding) atomsPdo.frames.length = depth;
      }
    },
  },
  atoms_pdo_leaf__deps: ['$atomsPdo', '$Asyncify', '$stackSave', '$stackRestore'],
  atoms_pdo_leaf: (fn, ptr, count, output) => {
    const frame = atomsPdo.frames[atomsPdo.depth - 1];
    if (Asyncify.state !== Asyncify.State.Rewinding) {
      const args = [];
      for (let i = 0; i < count; i++) {
        const slot = (ptr >>> 3) + 2 * i;
        const word = HEAPU64[slot];
        args.push(HEAPU64[slot + 1] ? word : Number(BigInt.asUintN(32, word)));
      }
      frame.call = {fn: Module.atomsPdoFunction(fn), args, output};
    }
    const stack = stackSave();
    try {
      const call = frame.call;
      const value = call.fn(...call.args);
      if (Asyncify.state === Asyncify.State.Unwinding) return 0;
      HEAPU64[call.output >>> 3] = BigInt.asUintN(64, BigInt(value ?? 0));
      frame.call = null;
      return 0;
    } catch (error) {
      stackRestore(stack);
      frame.failed = true;
      frame.error = error;
      return -1;
    }
  },
});

addToLibrary({
  atoms_pdo_open__deps: ['$atomsPdo', 'atoms_rs_open'],
  atoms_pdo_open: (dbh) => atomsPdo.enter(_atoms_rs_open, dbh),
  atoms_pdo_close__deps: ['$atomsPdo', 'atoms_rs_close'],
  atoms_pdo_close: (dbh) => atomsPdo.enter(_atoms_rs_close, dbh),
  atoms_pdo_exec__deps: ['$atomsPdo', 'atoms_rs_exec'],
  atoms_pdo_exec: (dbh, sql) => atomsPdo.enter(_atoms_rs_exec, dbh, sql),
  atoms_pdo_quote__deps: ['$atomsPdo', 'atoms_rs_quote'],
  atoms_pdo_quote: (dbh, input) => atomsPdo.enter(_atoms_rs_quote, dbh, input),
  atoms_pdo_begin__deps: ['$atomsPdo', 'atoms_rs_begin'],
  atoms_pdo_begin: (dbh) => atomsPdo.enter(_atoms_rs_begin, dbh),
  atoms_pdo_commit__deps: ['$atomsPdo', 'atoms_rs_commit'],
  atoms_pdo_commit: (dbh) => atomsPdo.enter(_atoms_rs_commit, dbh),
  atoms_pdo_rollback__deps: ['$atomsPdo', 'atoms_rs_rollback'],
  atoms_pdo_rollback: (dbh) => atomsPdo.enter(_atoms_rs_rollback, dbh),
  atoms_pdo_owns__deps: ['$atomsPdo', 'atoms_rs_owns'],
  atoms_pdo_owns: (dbh) => atomsPdo.enter(_atoms_rs_owns, dbh),
  atoms_pdo_last_id__deps: ['$atomsPdo', 'atoms_rs_last_id'],
  atoms_pdo_last_id: (dbh) => atomsPdo.enter(_atoms_rs_last_id, dbh),
  atoms_pdo_error_info__deps: ['$atomsPdo', 'atoms_rs_error_info'],
  atoms_pdo_error_info: (dbh, stmt, info) => atomsPdo.enter(_atoms_rs_error_info, dbh, stmt, info),
  atoms_pdo_destroy_stmt__deps: ['$atomsPdo', 'atoms_rs_destroy_stmt'],
  atoms_pdo_destroy_stmt: (stmt) => atomsPdo.enter(_atoms_rs_destroy_stmt, stmt),
  atoms_pdo_close_cursor__deps: ['$atomsPdo', 'atoms_rs_close_cursor'],
  atoms_pdo_close_cursor: (stmt) => atomsPdo.enter(_atoms_rs_close_cursor, stmt),
  atoms_pdo_execute__deps: ['$atomsPdo', 'atoms_rs_execute'],
  atoms_pdo_execute: (stmt) => atomsPdo.enter(_atoms_rs_execute, stmt),
  atoms_pdo_fetch__deps: ['$atomsPdo', 'atoms_rs_fetch'],
  atoms_pdo_fetch: (stmt, orientation) => atomsPdo.enter(_atoms_rs_fetch, stmt, orientation),
  atoms_pdo_describe__deps: ['$atomsPdo', 'atoms_rs_describe'],
  atoms_pdo_describe: (stmt, col) => atomsPdo.enter(_atoms_rs_describe, stmt, col),
  atoms_pdo_column__deps: ['$atomsPdo', 'atoms_rs_column'],
  atoms_pdo_column: (stmt, col, output) => atomsPdo.enter(_atoms_rs_column, stmt, col, output),
  atoms_pdo_close_cursors__deps: ['$atomsPdo', 'atoms_rs_close_cursors'],
  atoms_pdo_close_cursors: (frame) => atomsPdo.enter(_atoms_rs_close_cursors, frame),
  atoms_pdo_rshutdown__deps: ['$atomsPdo', 'atoms_rs_rshutdown'],
  atoms_pdo_rshutdown: () => atomsPdo.enter(_atoms_rs_rshutdown),
  atoms_pdo_prepare__deps: ['$atomsPdo', 'atoms_rs_prepare'],
  atoms_pdo_prepare: (dbh, sql, stmt, options) => atomsPdo.enter(_atoms_rs_prepare, dbh, stmt, options),
});
