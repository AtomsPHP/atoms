#!/usr/bin/env python3
"""Install, upgrade and restore one local Worker, preserving an actual customer's Atom."""
import argparse
import base64
import contextlib
from contextlib import closing
import hashlib
import http.client
import json
import os
from pathlib import Path
import signal
import time
import socket
import subprocess
from tempfile import TemporaryDirectory

ROOT = Path(__file__).resolve().parents[2]


# These expressions must run in the guest. Each uses a feature introduced by its series.
FEATURES = {
    "8.3": ("private const string FEATURE = 'typed class constant';", "self::FEATURE", "typed class constant"),
    "8.4": ("public private(set) string $feature = 'asymmetric visibility';", "$this->feature", "asymmetric visibility"),
    "8.5": ("", "'pipe operator' |> strtoupper(...)", "PIPE OPERATOR"),
}


def write_json(path, value):
    path.write_text(json.dumps(value, indent=2) + "\n")


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def fixture(app, minor, syntax):
    declaration, expression, _ = FEATURES[syntax]
    write_json(app / "atoms.json", {
        "project": "interpreter-recovery", "paths": {"atoms": "app/Atoms", "shared": "app/Atoms/Shared"},
        **({"php": minor} if minor else {}),
        "environments": {"local": {"worker_name": "interpreter-recovery", "account_id": "", "debug_endpoints": True}},
    })
    atoms = app / "app/Atoms"
    migrations = atoms / "VersionRoom/migrations"
    migrations.mkdir(parents=True, exist_ok=True)
    (migrations / "001_create_visits.sql").write_text(
        "CREATE TABLE visits (id INTEGER PRIMARY KEY, phase TEXT NOT NULL);\n")
    (migrations / "002_index_phase.sql").write_text("CREATE UNIQUE INDEX visit_phase ON visits (phase);\n")
    (atoms / "VersionRoom.php").write_text(r"""<?php

declare(strict_types=1);

namespace App\Atoms;

use Atoms\Atom;

final class VersionRoom extends Atom
{
    DECLARATION

    public function pdoRows(): array
    {
        $pdo = new \PDO('atoms:');
        return $pdo->query('SELECT phase FROM visits ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function state(): array
    {
        return [
            'version' => PHP_VERSION,
            'openssl' => OPENSSL_VERSION_TEXT,
            'intBytes' => PHP_INT_SIZE,
            'syntax' => EXPRESSION,
            'rows' => array_column($this->db()->query('SELECT phase FROM visits ORDER BY id'), 'phase'),
        ];
    }

    public function append(string $phase): array
    {
        $this->db()->execute('INSERT INTO visits (phase) VALUES (?)', [$phase]);
        return $this->state();
    }
}
""".replace("DECLARATION", declaration).replace("EXPRESSION", expression))


def boot_deploy(worker, output, version, log):
    """Exercise Wrangler's bundled modules with an empty SQLite store and no network access."""
    with TemporaryDirectory() as tmp:
        root = Path(tmp)
        (root / "files").symlink_to(output.resolve(), target_is_directory=True)
        (root / "probe.js").symlink_to(Path(__file__).with_name("deploy-probe.js").resolve())
        disk = root / "sqlite"
        disk.mkdir()
        modules = []
        for path in sorted(output.rglob("*"), key=lambda p: (p.name != "index.js", p)):
            kind = {".js": "esModule", ".mjs": "esModule", ".wasm": "wasm"}.get(path.suffix)
            if kind:
                name = path.relative_to(output).as_posix()
                modules.append(f'(name = {json.dumps(name)}, {kind} = embed {json.dumps("files/" + name)})')
        secret = base64.b64encode(os.urandom(32)).decode("ascii")
        config = root / "deploy.capnp"
        config.write_text(f'''using Workerd = import "/workerd/workerd.capnp";
const config :Workerd.Config = (services = [
  (name = "proof", worker = (compatibilityDate = "2026-08-01",
    modules = [(name = "probe.js", esModule = embed "probe.js")],
    bindings = [(name = "WORKER", service = "subject"), (name = "PHP", text = "{version}")],
    globalOutbound = "blocked")),
  (name = "subject", worker = (compatibilityDate = "2026-08-01", compatibilityFlags = ["nodejs_compat"],
    modules = [{", ".join(modules)}],
    durableObjectNamespaces = [(className = "AtomDurableObject", uniqueKey = "release-probe", enableSql = true)],
    durableObjectStorage = (localDisk = "disk"),
    bindings = [(name = "ATOMS", durableObjectNamespace = (className = "AtomDurableObject")),
                (name = "ATOMS_BEARER_AUTH", text = "disabled"), (name = "ATOMS_SHARED_SECRET", text = "{secret}")],
    globalOutbound = "blocked")),
  (name = "blocked", network = (allow = [])),
  (name = "disk", disk = (writable = true)),
]);
''')
        with log.open("wb") as stream:
            subprocess.run([worker / "node_modules/.bin/workerd", "test", "-d", f"disk={disk}", config],
                           stdout=stream, stderr=subprocess.STDOUT, check=True)


def request(port, path, args=None):
    with closing(http.client.HTTPConnection("127.0.0.1", port, timeout=60)) as conn:
        if args is None:
            conn.request("GET", path)
        else:
            conn.request("POST", path, json.dumps({"args": args}), {"Content-Type": "application/json"})
        response = conn.getresponse()
        body = response.read()
        if response.status != 200:
            raise RuntimeError(f"{path} returned {response.status}: {body.decode(errors='replace')}")
        return json.loads(body)


@contextlib.contextmanager
def dev(cli, app, env, log, rebuild=False):
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    with log.open("wb") as stream:
        command = [cli, "dev", "--env", "local", "--port", str(port)]
        if not rebuild:
            command.append("--no-build")
        server = subprocess.Popen(command,
                                  cwd=app, env=env, stdout=stream, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            deadline = time.monotonic() + 120
            while server.poll() is None and time.monotonic() < deadline:
                try:
                    request(port, "/healthz")
                    break
                except (OSError, http.client.HTTPException):
                    time.sleep(.3)
            else:
                raise RuntimeError(f"Worker did not start. See {log}")
            yield port
        finally:
            with contextlib.suppress(ProcessLookupError):
                os.killpg(server.pid, signal.SIGTERM)
            try:
                server.wait(timeout=10)
            except subprocess.TimeoutExpired:
                pass
            with contextlib.suppress(ProcessLookupError):
                os.killpg(server.pid, signal.SIGKILL)
            server.wait()


def install(package, action, app, env):
    worker = app / "atoms-worker"
    subprocess.run(["npm", "exec", "--yes", "--package=" + str(package), "--",
                    "atoms-runtime-cloudflare", action, "atoms-worker"], cwd=app, env=env, check=True)
    stamp = json.loads((worker / "atoms-runtime.json").read_bytes())
    # The published template is the authority on the resulting file ownership.
    embedded = subprocess.check_output(["tar", "-xOzf", package, "package/template/atoms-runtime.json"])
    assert stamp == json.loads(embedded)
    lock = digest(worker / "package-lock.json")
    subprocess.run(["npm", "ci", "--no-audit", "--no-fund"], cwd=worker, env=env, check=True)
    assert digest(worker / "package-lock.json") == lock
    return stamp


def build(cli, app, env, dest):
    subprocess.run([cli, "validate"], cwd=app, env=env, check=True)
    subprocess.run([cli, "build", "--out", dest], cwd=app, env=env, check=True)
    archive, = dest.glob("bundle-*.tar.gz")
    return archive, dest / "manifest.json"


def exercise(old_package, package, old_cli, results, *, fresh):
    cli = ROOT / "packages/cli/bin/atoms"
    with TemporaryDirectory(prefix="atoms-install-", dir=results.parent) as temp:
        app = Path(temp)
        worker = app / "atoms-worker"
        env = {k: v for k, v in os.environ.items() if not k.startswith(("ATOMS_", "CF_", "CLOUDFLARE_", "WRANGLER_"))}
        env.update(CI="true", WRANGLER_SEND_METRICS="false", XDG_CONFIG_HOME=str(app / "config"))
        write_json(app / "composer.json", {"name": "example/recovery", "autoload": {"psr-4": {"App\\": "app/"}}})
        write_json(app / "atoms-composer.json", {"require": {}})
        pins = {minor: json.loads((ROOT / "cloudflare/php-wasm-build/targets" / minor / "candidate.json").read_bytes())
                for minor in FEATURES}
        if fresh:
            fixture(app, "8.6", "8.3")
            rejected = subprocess.run([cli, "validate"], cwd=app, env=env, capture_output=True, text=True)
            assert rejected.returncode and "ATOMS-E070" in rejected.stdout + rejected.stderr
            for selection in (None, *pins):
                minor = selection or "8.3"
                fixture(app, selection, minor)
                _, manifest = build(cli, app, env, app / ("before-init-" + (selection or "default")))
                assert json.loads(manifest.read_bytes())["toolchain"]["php"] == minor
            assert not worker.exists()
        fixture(app, None, "8.3")
        rows = []
        history = []
        stamp = install(package if fresh else old_package, "init", app, env)
        baseline_lock = digest(worker / "package-lock.json")
        installed_pins = worker / "release/interpreters.json"
        if installed_pins.exists():
            baseline_pin = json.loads(installed_pins.read_bytes())["8.3"]
            baseline_php = baseline_pin["version"].split("-")[0]
            baseline_wasm = baseline_pin["wasm"]["sha256"]
        else:
            # Read the published 0.11.0 scaffold's own release record.
            script = "import {phpRuntime} from './scripts/lib/php-runtimes.mjs'; console.log(JSON.stringify(phpRuntime('8.3')))"
            prior = json.loads(subprocess.check_output(["node", "--input-type=module", "-e", script], cwd=worker))
            baseline_php, baseline_wasm = prior["version"], prior["wasmSha256"]
        config = worker / "wrangler.jsonc"
        text = config.read_text().replace('"ATOMS_LOG_LEVEL": "info"', '"ATOMS_LOG_LEVEL": "info", "ATOMS_BEARER_AUTH": "disabled"')
        assert '"ATOMS_BEARER_AUTH": "disabled"' in text
        config.write_text(text + "\n// Customer-owned configuration.\n")
        config_sha = digest(config)
        # Archive and manifest are kept outside the scaffold for rollback.
        saved_archive, saved_manifest = build(cli if fresh else old_cli, app, env, app / "saved-bundle")
        if fresh:
            stages = [("fresh-default", None), *[("fresh-" + series, series) for series in pins]]
        else:
            stages = [("baseline", None), ("default", None), ("explicit-8.3", "8.3"),
                      ("owned-8.4", "8.4"), ("owned-8.5", "8.5"), ("back-to-8.3", "8.3"), ("restored", None)]
        for phase, selection in stages:
            minor = selection or "8.3"
            old = phase in ("baseline", "restored")
            current_cli = old_cli if old else cli
            fixture(app, selection, minor)
            obsolete = set()
            if phase in ("default", "restored"):
                previous = stamp
                stamp = install(old_package if old else package, "upgrade", app, env)
                obsolete = set(previous["runtime_owned"]) - set(stamp["runtime_owned"])
                assert all(not (worker / name).exists() for name in obsolete)
                assert stamp != previous
            assert digest(config) == config_sha
            if old:
                archive, manifest = saved_archive, saved_manifest
                assert digest(worker / "package-lock.json") == baseline_lock
            else:
                archive, manifest = build(cli, app, env, app / ("build-" + phase))
                if minor == "8.3":
                    assert digest(archive) == digest(saved_archive), "common customer archive changed"
            assert json.loads(manifest.read_bytes())["toolchain"]["php"] == minor
            out = app / ("out-" + phase)
            with (results / (phase + ".dry-run.log")).open("w") as log:
                subprocess.run([current_cli, "deploy", "--env", "local", "--bundle", archive,
                                "--manifest", manifest, "--dry-run", "--outdir", out],
                               cwd=app, env=env, stdout=log, stderr=subprocess.STDOUT, check=True)
            wasm, = out.rglob("*.wasm")
            packaged = digest(wasm)
            pin = pins[minor]
            if old:
                assert packaged == baseline_wasm
            else:
                staged = worker / ".php-wasm" / (pin["wasm"]["sha256"] + "-" + pin["glue"]["sha256"])
                for part in ("wasm", "glue"):
                    file = staged / pin[part]["path"]
                    assert file.stat().st_size == pin[part]["bytes"] and digest(file) == pin[part]["sha256"]
                assert packaged == pin["wasm"]["sha256"]
                if fresh:
                    boot_deploy(worker, out, pin["version"].split("-")[0], results / (phase + ".offline.log"))
            with dev(current_cli, app, env, results / (phase + ".dev.log"), rebuild=phase in ("fresh-default", "default")) as port:
                route = "/invoke/VersionRoom/persistent/"
                before = request(port, route + "state", [])["result"]
                if not old:
                    assert request(port, route + "pdoRows", [])["result"] == before["rows"]
                after = request(port, route + "append", [phase])["result"]
                if not old:
                    assert request(port, route + "pdoRows", [])["result"] == after["rows"]
                info = request(port, "/debug/VersionRoom/persistent/info")["info"]
            assert before["rows"] == rows and after == dict(before, rows=[*rows, phase])
            assert before["syntax"] == FEATURES[minor][2] and before["intBytes"] == 8
            assert before["version"] == (baseline_php if old else pin["version"].split("-")[0])
            if not old:
                assert before["openssl"].startswith("OpenSSL 3.5.9 ")
            assert info["user_version"] == 2 and info["do_id"]
            if history:
                assert info["do_id"] == history[0]["doId"]
            if phase == "restored":
                assert packaged == history[0]["dryRunWasmSha256"]
                assert before["openssl"] == history[0]["before"]["openssl"]
            rows = after["rows"]
            history.append({"phase": phase, "doId": info["do_id"], "userVersion": info["user_version"],
                            "manifestPhp": minor, "wranglerSha256": config_sha, "bundleSha256": digest(archive),
                            "dryRunWasmSha256": packaged, "lockSha256": digest(worker / "package-lock.json"),
                            "removedOwnedFiles": sorted(obsolete),
                            "scaffoldSha256": digest(old_package if old else package), "before": before, "after": after})
            write_json(results / ("fresh-install.json" if fresh else "recovery.json"), history)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--upgrade-only", action="store_true", help="run the saved-room upgrade and rollback sequence")
    parser.add_argument("baseline_scaffold", type=Path)
    parser.add_argument("scaffold", type=Path)
    parser.add_argument("baseline_cli", type=Path)
    parser.add_argument("results", type=Path)
    args = parser.parse_args()
    results = args.results.resolve()
    results.mkdir(parents=True, exist_ok=False)
    for fresh in ((False,) if args.upgrade_only else (True, False)):
        exercise(args.baseline_scaffold.resolve(strict=True), args.scaffold.resolve(strict=True),
                 args.baseline_cli.resolve(strict=True), results, fresh=fresh)
