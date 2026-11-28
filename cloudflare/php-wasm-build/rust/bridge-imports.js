// Stock emcc library imports. PHP enters its normal module startup with the
// static Rust module as its additional module. Zend owns registration.
addToLibrary({
  atoms_startup__deps: ['atoms_use_php_ini', 'php_module_startup'],
  atoms_startup: (sapi) => {
    _atoms_use_php_ini(sapi);
    return _php_module_startup(sapi, Module.atomsBridge);
  },
  atoms_register_opcache__deps: ['zend_register_extension', 'zend_extension_entry'],
  atoms_register_opcache: () => {
    _zend_register_extension(_zend_extension_entry, 0);
    return 0;
  },
  atoms_roundtrip_i64: (value) => Module.atomsRoundtripI64(value),
});

// The OPcache preloader references initgroups in its forked-child path.
// A Worker has no supplementary process groups.
addToLibrary({
  initgroups__deps: ['__errno_location'],
  initgroups: () => {
    HEAP32[___errno_location() >> 2] = {{{ cDefs.ENOSYS }}};
    return -1;
  },
});

// The private embed build has no CLI descriptor access or PHP Fiber backend.
// Trap at entry if a fixture exceeds that surface.
addToLibrary({
  getdtablesize: () => { throw Error('php://fd is unavailable in the wasm SAPI'); },
  getcontext: () => { throw Error('PHP Fibers are unavailable in the minimal build'); },
  makecontext: () => { throw Error('PHP Fibers are unavailable in the minimal build'); },
  swapcontext: () => { throw Error('PHP Fibers are unavailable in the minimal build'); },
});

addToLibrary({
  atoms_send__deps: ['$Asyncify'],
  atoms_send: (ptr, len) => {
    if (Asyncify.state === Asyncify.State.Rewinding) {
      return Asyncify.handleSleep(() => {});
    }
    const message = Module.decodeMessage(ptr, len);
    if (message.startsWith('!')) return Module.syncMessage(message.slice(1));
    if (!message.startsWith('~')) throw Error('Unknown Atoms message tag');
    return Asyncify.handleSleep(wake => Module.parkMessage(message.slice(1), wake));
  },
});
