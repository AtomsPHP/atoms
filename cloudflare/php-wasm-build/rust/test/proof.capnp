using Workerd = import "/workerd/workerd.capnp";
const config :Workerd.Config = (
  services = [
    (name = "probe", worker = (
      compatibilityDate = "2026-07-01",
      modules = [
        (name = "worker.js", esModule = embed "worker.js"),
        (name = "php.mjs", esModule = embed "php.mjs"),
        (name = "php.wasm", wasm = embed "php.wasm"),
        (name = "pdo-runtime.js", esModule = embed "pdo-runtime.js"),
        (name = "expected-version.txt", text = embed "expected-version.txt"),
        (name = "syntax.php", text = embed "syntax.php"),
        (name = "guest.php", text = embed "guest.php"),
        (name = "timestamps.php", text = embed "timestamps.php"),
        (name = "parity.php", text = embed "parity.php"),
        (name = "inspect-guest.php", text = embed "inspect-guest.php"),
        (name = "loader.js", esModule = embed "loader.js"),
        (name = "bridge.js", esModule = embed "bridge.js"),
        (name = "int64.js", esModule = embed "int64.js"),
        (name = "config.js", esModule = embed "config.js"),
        (name = "errors.js", esModule = embed "errors.js"),
      ],
      durableObjectNamespaces = [(className = "Probe", uniqueKey = "atoms-rust-php", enableSql = true)],
      durableObjectStorage = (localDisk = "disk"),
      bindings = [(name = "PROBE", durableObjectNamespace = (className = "Probe"))],
    )),
    (name = "disk", disk = (writable = true)),
  ],
);
