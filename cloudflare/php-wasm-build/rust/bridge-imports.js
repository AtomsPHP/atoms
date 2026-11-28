// Stock emcc library imports. PHP enters its normal module startup with the
// static Rust module as its additional module. Zend owns registration.
addToLibrary({
  atoms_startup__deps: ['php_module_startup'],
  atoms_startup: (sapi) => _php_module_startup(sapi, Module.atomsBridge),
  atoms_roundtrip_i64: (value) => Module.atomsRoundtripI64(value),
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
