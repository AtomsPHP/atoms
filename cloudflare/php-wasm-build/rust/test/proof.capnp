using Workerd = import "/workerd/workerd.capnp";
const config :Workerd.Config = (
  services = [
    (name = "probe", worker = (
      compatibilityDate = "2026-07-01",
      modules = [
        (name = "worker.js", esModule = embed "worker.js"),
        (name = "php.mjs", esModule = embed "php.mjs"),
        (name = "php.wasm", wasm = embed "php.wasm"),
        (name = "guest.php", text = embed "guest.php"),
      ],
      durableObjectNamespaces = [(className = "Probe", uniqueKey = "atoms-rust-php", enableSql = true)],
      durableObjectStorage = (localDisk = "disk"),
      bindings = [(name = "PROBE", durableObjectNamespace = (className = "Probe"))],
    )),
    (name = "disk", disk = (writable = true)),
  ],
);
