const encoder = new TextEncoder();
const decoder = new TextDecoder();

// Each adapter owns one embed request, including its wakes and transfer.
// A caller can discard the entire Wasm instance while the request is parked.
export async function bootPHP(createModule, wasm, {onSync, onPark, print, printErr}) {
  let closed = false;
  let started = false;
  let transfer = null;
  let cancel;
  const module = await createModule({
    instantiateWasm(imports, ready) {
      const instance = new WebAssembly.Instance(wasm, imports);
      ready(instance, wasm);
      return instance.exports;
    },
    print,
    printErr,
    atomsRoundtripI64: value => value,
  });
  function requireOpen() {
    if (closed) throw Error('PHP instance has closed');
  }
  function releaseTransfer() {
    if (transfer) {
      module._free(transfer.ptr);
      transfer = null;
    }
  }
  function writeTransfer(reply) {
    requireOpen();
    if (transfer || typeof reply !== 'string') throw Error('Invalid Atoms reply');
    const bytes = encoder.encode(reply);
    if (!bytes.length) return 0;
    if (bytes.length > 0x7fffffff) throw RangeError('Reply exceeds the signed Wasm length');
    const ptr = module._malloc(bytes.length) >>> 0;
    if (!ptr || ptr + bytes.length > module.HEAPU8.byteLength) throw Error('Cannot allocate reply');
    module.HEAPU8.set(bytes, ptr);
    transfer = {ptr, length: bytes.length, offset: 0};
    return bytes.length;
  }
  module.decodeMessage = (ptr, len) => {
    requireOpen();
    const start = ptr >>> 0;
    const end = start + (len >>> 0);
    if (!start || end > module.HEAPU8.length) throw RangeError('Invalid PHP message span');
    return decoder.decode(module.HEAPU8.subarray(start, end));
  };
  module.syncMessage = json => writeTransfer(onSync(json));
  module.parkMessage = (json, wake) => {
    let used = false;
    onPark(json, reply => {
      requireOpen();
      if (used) throw Error('PHP wake already consumed');
      used = true;
      wake(writeTransfer(reply));
    });
  };

  const fs = module.FS;
  const device = fs.makedev(87, 0);
  fs.registerDevice(device, {
    open(stream) { stream.seekable = false; },
    read(_stream, buffer, offset, length) {
      requireOpen();
      if (!transfer) return 0;
      const size = Math.min(length, transfer.length - transfer.offset);
      const start = transfer.ptr + transfer.offset;
      buffer.set(module.HEAPU8.subarray(start, start + size), offset);
      transfer.offset += size;
      if (transfer.offset === transfer.length) releaseTransfer();
      return size;
    },
  });
  fs.mkdev('/dev/atoms-reply', 0o400, device);
  fs.mkdirTree('/home/web_user');
  fs.mkdirTree('/internal/shared/opcache');
  module.atomsBridge = module._atoms_prepare();

  return {
    mkdir: path => fs.mkdirTree(path),
    writeFile: (path, data) => fs.writeFile(path, data),
    fileExists: path => fs.analyzePath(path).exists,
    get memoryBytes() { return module.HEAPU8.byteLength; },
    async run({code}) {
      requireOpen();
      if (started) throw Error('PHP request already started');
      started = true;
      fs.writeFile('/atoms/main.php', code);
      if (module._php_embed_init(0, 0) !== 0) throw Error('php_embed_init failed');
      const name = encoder.encode('/atoms/main.php\0');
      const ptr = module._malloc(name.length);
      if (!ptr) throw Error('Cannot allocate script path');
      module.HEAPU8.set(name, ptr);
      const file = module._atoms_script_handle();
      module._zend_stream_init_filename(file, ptr);
      module._free(ptr);
      try {
        let ok = module._php_execute_script(file);
        if (module.Asyncify.currData) {
          const discarded = new Promise(resolve => { cancel = () => resolve(false); });
          ok = await Promise.race([module.Asyncify.whenDone(), discarded]);
        }
        if (closed || !ok) return {exitCode: 255};
        closed = true;
        module._zend_destroy_file_handle(file);
        module._php_embed_shutdown();
        return {exitCode: 0};
      } finally {
        closed = true;
        cancel = null;
        releaseTransfer();
      }
    },
    exit() {
      if (closed) return;
      closed = true;
      releaseTransfer();
      cancel?.();
    },
  };
}
