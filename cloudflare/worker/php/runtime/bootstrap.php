<?php

/**
 * The guest entry point: one activation, then the parked turn loop.
 *
 * `php.run()` is a REQUEST — classes, constants and globals are torn down when
 * it returns — established by the pre-MVP spike. An Atom whose state lives in PHP
 * memory therefore cannot be one `php.run()` per turn. Everything below happens
 * inside ONE `php.run()` that does not return until the host asks for shutdown:
 * the guest parks itself on the `turn.await` door between turns and the host
 * resumes it with the next envelope, so the PHP stack — and with it the Atom
 * object and all its in-memory state — is never unwound. Shape ported from
 * the spike's loop.
 *
 * Load order, and the composed entry script the JS host must run, are
 * documented in ../README.md.
 *
 * No declare(strict_types=1) — a declare() must be the very first statement of
 * a file and this one is `require`d from a host-composed script; the spike hit
 * hard fatals on exactly this. The verbatim atoms-core files
 * keep their own declare() because they are only ever `require`d, one file each.
 */

namespace Atoms\Cf;

use Atoms\Atom;
use Atoms\Migrations\MigrationSet;
use Atoms\Migrations\Migrator;
use Atoms\Runtime\LifecycleInvoker;
use Atoms\Serialization\Serializer;

/** Where the host writes this prelude, unless $CFG['paths']['runtime'] says otherwise. */
const RUNTIME_DIR_DEFAULT = '/atoms/runtime';

/** Where the host writes the verbatim atoms/core sources, unless $CFG overrides it. */
const CORE_DIR_DEFAULT = '/atoms/core/src';

/**
 * The atoms/core files, in dependency order. Verbatim copies — this list is the
 * runtime's own, not something the bundle gets to choose.
 *
 * @return list<string> paths relative to the core source directory
 */
function core_files()
{
    return [
        'Errors/ErrorCode.php',
        'Errors/CatalogEntry.php',
        'Errors/ErrorCatalog.php',
        'Errors/AtomsError.php',
        'Attributes/MethodsFor.php',
        'Attributes/SharedWithAtoms.php',
        'Serialization/Payload.php',
        'Serialization/SerializationException.php',
        'Serialization/Serializer.php',
        'Database.php',
        'AtomJob.php',
        'AtomMethods.php',
        'Websocket/Connection.php',
        'Websocket/Message.php',
        'Timers/Timers.php',
        'Runtime/AtomContext.php',
        'Atom.php',
        'Runtime/LifecycleInvoker.php',
        'Migrations/Migration.php',
        'Migrations/MigrationEntry.php',
        'Migrations/MigrationSet.php',
        'Migrations/Migrator.php',
    ];
}

/**
 * The rest of the Atoms\Cf prelude, in dependency order. host.php and int64.php
 * are required by hand below, before anything can fail usefully.
 *
 * @return list<string> paths relative to the runtime directory
 */
function runtime_files()
{
    return [
        'BootstrapError.php',
        'MigrationsGlobShim.php',
        'AtomsNotSupported.php',
        'FetchMode.php',
        'NamedParams.php',
        'SqlBridge.php',
        'AtomsStatement.php',
        'AtomsPDO.php',
        'BridgeDatabase.php',
        // The callback channel (app()/dispatch()). No autoloader is active yet
        // at this point in boot, so this really is dependency order: the
        // CallbackError base before its subclasses, and the shared cross
        // helper/proxy last, since they reference the exception classes only
        // inside method bodies (which PHP resolves lazily) but read most
        // naturally after them.
        'CallbackError.php',
        'CallbackNotConfigured.php',
        'CallbackUnsigned.php',
        'CallbackInTransaction.php',
        'CallbackFailed.php',
        'JobNotEncodable.php',
        'TurnDeadlineExceeded.php',
        'CallbackChannel.php',
        'CallbackAppProxy.php',
        'CfAtomContext.php',
    ];
}

/**
 * Plain \RuntimeException rather than BootstrapError: this runs before (and in
 * order to load) the class that would otherwise report it.
 *
 * @param string $dir
 * @param list<string> $files
 * @throws \RuntimeException when a file the runtime owns is missing from MEMFS
 */
function require_all($dir, array $files)
{
    foreach ($files as $relative) {
        $path = rtrim($dir, '/') . '/' . $relative;

        if (!is_file($path)) {
            throw new \RuntimeException(
                sprintf('Atoms runtime file %s was not written into the guest filesystem.', $path)
            );
        }

        require_once $path;
    }
}

/**
 * Index the bundle's PHP files by declared symbol and register an autoloader
 * for them, so a customer Atom can reference Payload DTOs, enums and helper
 * classes that live in other files without the runtime imposing a load order.
 *
 * Best effort by design (bundle_format 0 carries no class map): declarations
 * are recognised at the start of a line, which covers every named top-level
 * class, interface, trait and enum. Anonymous classes and conditionally
 * declared ones are not indexed — they cannot be autoloaded anyway.
 *
 * @param list<string> $files guest paths the host wrote into MEMFS
 * @param list<string> $exclude paths that must never be autoloaded (migrations)
 * @return int the number of indexed symbols
 */
function register_bundle_autoloader(array $files, array $exclude)
{
    $skip = array_flip($exclude);
    $index = [];

    foreach ($files as $path) {
        if (substr($path, -4) !== '.php' || isset($skip[$path]) || !is_file($path)) {
            continue;
        }

        $source = file_get_contents($path);
        if ($source === false) {
            continue;
        }

        $namespace = '';
        if (preg_match('/^[ \t]*namespace[ \t]+([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff\\\\]*)[ \t]*[;{]/m', $source, $m) === 1) {
            $namespace = $m[1] . '\\';
        }

        if (preg_match_all(
            '/^[ \t]*(?:(?:final|abstract|readonly)[ \t]+)*(?:class|interface|trait|enum)[ \t]+([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)/m',
            $source,
            $matches
        ) > 0) {
            foreach ($matches[1] as $name) {
                $index[strtolower($namespace . $name)] = $path;
            }
        }
    }

    spl_autoload_register(function ($class) use ($index) {
        $key = strtolower(ltrim((string) $class, '\\'));

        if (isset($index[$key])) {
            require_once $index[$key];
        }
    });

    return count($index);
}

/**
 * Apply this Atom type's pending migrations with the real
 * `Atoms\Migrations\Migrator`, which runs each one in its own transaction and
 * tracks progress in `PRAGMA user_version` — a pragma the host intercepts and
 * maps onto `__atoms_meta` (mvp-spec.md §SQL bridge details), so the unmodified
 * Migrator works over the bridge.
 *
 * @param BridgeDatabase $db
 * @param list<string> $paths migration files, from the manifest
 * @param string $type
 * @return int migrations applied
 * @throws BootstrapError
 */
function apply_migrations(BridgeDatabase $db, array $paths, $type)
{
    if ($paths === []) {
        return 0;
    }

    $directories = [];
    foreach ($paths as $path) {
        if (!is_file($path)) {
            throw new BootstrapError(
                'internal',
                sprintf('Migration %s for atom type %s is missing from the guest filesystem.', $path, $type)
            );
        }

        $directories[dirname($path)] = true;
    }

    if (count($directories) !== 1) {
        throw new BootstrapError(
            'internal',
            sprintf(
                'Atom type %s has migrations in %d directories; MigrationSet::fromDirectory() scans exactly one.',
                $type,
                count($directories)
            )
        );
    }

    $directory = (string) array_key_first($directories);
    $set = MigrationSet::fromDirectory($directory);

    // MigrationSet globs the directory. If the guest filesystem ever answers a
    // glob differently from what the manifest promised, migrations would
    // silently not apply — which is exactly the failure this runtime must never
    // have. Fail the activation loudly instead.
    if (count($set) !== count($paths)) {
        throw new BootstrapError(
            'internal',
            sprintf(
                'Migration discovery mismatch for atom type %s in %s: the manifest lists %d file(s), '
                . 'MigrationSet found %d.',
                $type,
                $directory,
                count($paths),
                count($set)
            )
        );
    }

    $migrator = new Migrator();

    return $migrator->apply($db, $set);
}

/**
 * Resolve an invocable Atom method, refusing anything that is not a customer
 * method: private/protected/static/abstract members, magic methods, and the
 * base class's own surface (the constructor and the WebSocket handlers, which
 * are out of scope for the MVP).
 *
 * @param object $atom
 * @param mixed $method
 * @return \ReflectionMethod
 * @throws BootstrapError with code 'method_not_found'
 */
function invocable_method($atom, $method)
{
    $class = get_class($atom);

    if (!is_string($method) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method) !== 1) {
        throw new BootstrapError('method_not_found', sprintf('%s has no method %s.', $class, var_export($method, true)));
    }

    if (!method_exists($atom, $method)) {
        throw new BootstrapError('method_not_found', sprintf('%s has no method %s().', $class, $method));
    }

    $reflection = new \ReflectionMethod($atom, $method);

    if (!$reflection->isPublic() || $reflection->isStatic() || $reflection->isAbstract()) {
        throw new BootstrapError('method_not_found', sprintf('%s::%s() is not an invocable Atom method.', $class, $method));
    }

    if (strncmp($reflection->getName(), '__', 2) === 0) {
        throw new BootstrapError('method_not_found', sprintf('%s::%s() is a magic method.', $class, $method));
    }

    if ($reflection->getDeclaringClass()->getName() === Atom::class) {
        throw new BootstrapError(
            'method_not_found',
            sprintf('%s::%s() is part of the Atom base class, not of %s.', Atom::class, $method, $class)
        );
    }

    return $reflection;
}

/**
 * The spec's turn-result error envelope. Traces never appear here — they go to
 * the host's structured log instead (mvp-spec.md §Turn-result envelope).
 *
 * @param string $code
 * @param string $message
 * @param string|null $class FQCN of the throwable, null when there was none
 * @return array<string, mixed>
 */
function error_envelope($code, $message, $class)
{
    return [
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
            'class' => $class,
        ],
    ];
}

/**
 * Force a string into printable ASCII.
 *
 * Exception messages reach the wire, and `json_encode()` fails outright on
 * invalid UTF-8 — a customer throwing `new \RuntimeException("\xB1")` would
 * otherwise make the turn result unencodable and take the residency with it.
 *
 * @param mixed $text
 * @param int $limit
 * @return string
 */
function ascii_excerpt($text, $limit = 500)
{
    $out = preg_replace('/[^\x20-\x7E]/', '?', (string) $text);

    return strlen((string) $out) > $limit ? substr((string) $out, 0, $limit) . '...' : (string) $out;
}

/**
 * Guarantee that a turn-result envelope can actually be parked.
 *
 * `turn_loop()` hands the envelope to `host_park()`, which `json_encode()`s it.
 * Encoding fails on invalid UTF-8, on a recursive structure, and — the case
 * that matters most — on anything nested past json_encode()'s depth limit, all
 * of which a customer method can return. An unencodable envelope must not
 * become a \RuntimeException thrown out of the loop: that unwinds php.run() and
 * poisons the residency for a plain application bug. The whole park request is
 * trialled here, so the depth budget checked is exactly the one the real call
 * will use.
 *
 * @param array<string, mixed> $envelope
 * @param mixed $method
 * @return array<string, mixed> the envelope, or an encodable error envelope
 */
function encodable_envelope(array $envelope, $method)
{
    if (json_encode(['op' => 'turn.await', 'result' => $envelope], JSON_UNESCAPED_SLASHES) !== false) {
        return $envelope;
    }

    $why = json_last_error_msg();

    host_log('error', [
        'event' => 'turn_result_unencodable',
        'method' => is_string($method) ? $method : '',
        'reason' => $why,
    ]);

    // Deliberately built from ASCII literals only, so this one cannot fail too.
    return error_envelope(
        'atom_exception',
        sprintf(
            'The value returned by %s() cannot cross the Atoms bridge: %s. '
            . 'Return data that is JSON-encodable, UTF-8 clean and not deeply nested.',
            ascii_excerpt(is_string($method) ? $method : '?', 100),
            ascii_excerpt($why, 200)
        ),
        null
    );
}

/**
 * Settle a transaction the turn left open.
 *
 * The host is still parked inside `ctx.storage.transactionSync(cb)` at this
 * point, and it refuses every park op but tx.commit/tx.rollback while it is —
 * so parking at `turn.await` here would strand the guest. Forgetting
 * `commit()`, or catching an exception above the frame that opened the
 * transaction, is an ordinary application bug: it must produce a typed
 * `atom_exception` and leave the Atom serving turns, not a destroyed residency
 * that every retry destroys again. The write set is discarded, because a turn
 * that never committed must not appear to have.
 *
 * @param SqlBridge $bridge
 * @param array<string, mixed> $identity
 * @param mixed $method
 * @return string|null null when nothing was open, else what to report
 */
function settle_open_transaction(SqlBridge $bridge, array $identity, $method)
{
    if (!$bridge->inTransaction()) {
        return null;
    }

    $name = ascii_excerpt(is_string($method) ? $method : '?', 100);
    $detail = '';

    try {
        $bridge->rollback();
    } catch (\Throwable $e) {
        // SqlBridge::rollback() clears its own flag in a finally, so the next
        // turn still starts from a known state.
        $detail = ' The rollback itself failed: ' . ascii_excerpt($e->getMessage(), 200) . '.';
    }

    host_log('error', [
        'event' => 'transaction_left_open',
        'atom_type' => $identity['type'],
        'atom_id' => $identity['id'],
        'method' => $name,
        'message' => 'the turn ended inside an open transaction; it was rolled back',
    ]);

    return sprintf(
        '%s() returned with a database transaction still open. It was rolled back, so nothing '
        . 'it wrote was kept. Every transaction must be committed or rolled back within the turn '
        . 'that opened it; prefer $this->db()->transaction(...), which does that for you.%s',
        $name,
        $detail
    );
}

/**
 * Run one turn and produce its result envelope. Never throws: a turn's failure
 * is data the host reports, not a reason to poison the residency.
 *
 * @param object $atom
 * @param Serializer $serializer
 * @param SqlBridge $bridge the shared SQL seam, so an abandoned transaction can
 *                          be settled before the turn boundary is reached
 * @param array<string, mixed> $identity {type, id}
 * @param mixed $method
 * @param list<mixed> $args still int64-TAGGED; decoded below, inside the guard
 * @return array<string, mixed>
 */
function run_turn($atom, Serializer $serializer, SqlBridge $bridge, array $identity, $method, array $args)
{
    try {
        // Decoding happens INSIDE the guard on purpose. The args come from the
        // client, so a malformed or out-of-range {"$atoms_int64":...} tag is
        // attacker-controlled input; decoding it in turn_loop() would let the
        // RuntimeException escape the loop, unwind php.run() and poison the
        // whole residency. The host rejects such a tag with `int64_range`
        // before it ever gets here — this is the second line of defence.
        $args = int64_decode($args);

        try {
            $reflection = invocable_method($atom, $method);
        } catch (BootstrapError $e) {
            return error_envelope('method_not_found', $e->getMessage(), null);
        }

        try {
            $callArgs = $serializer->denormalizeArguments($args, $reflection);
            $value = $reflection->invokeArgs($atom, $callArgs);
            $normalized = $serializer->normalize($value);
        } catch (\Throwable $e) {
            host_log('error', [
                'event' => 'turn_failed',
                'atom_type' => $identity['type'],
                'atom_id' => $identity['id'],
                'method' => (string) $method,
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // A throw that escaped the customer's own transaction handling can
            // leave one open; settle it before the turn boundary. The original
            // throwable is still what the caller is told about.
            settle_open_transaction($bridge, $identity, $method);

            // An uncaught turn-deadline overrun is reported as its own
            // turn-result code, not folded into atom_exception, so the client
            // can retry it (atoms/client already maps turn_deadline_exceeded ->
            // TurnDeadlineExceeded and only retries when the call site opts in
            // — design doc §2.4).
            if ($e instanceof TurnDeadlineExceeded) {
                return error_envelope('turn_deadline_exceeded', $e->getMessage(), get_class($e));
            }

            return error_envelope('atom_exception', $e->getMessage(), get_class($e));
        }

        $leaked = settle_open_transaction($bridge, $identity, $method);
        if ($leaked !== null) {
            return error_envelope('atom_exception', $leaked, null);
        }

        return ['ok' => true, 'result' => int64_encode($normalized)];
    } catch (\Throwable $e) {
        // Anything that escapes the inner handlers is a runtime bug, not the
        // customer's: envelope construction, int64 tagging, reflection.
        host_log('error', [
            'event' => 'turn_internal_error',
            'atom_type' => $identity['type'],
            'atom_id' => $identity['id'],
            'method' => is_string($method) ? $method : '',
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return error_envelope('internal', $e->getMessage(), get_class($e));
    }
}

/**
 * Park between turns forever. The `result` field carries the PREVIOUS turn's
 * envelope and is null on the first park after boot (mvp-spec.md §Park ops).
 *
 * @param object $atom
 * @param SqlBridge $bridge
 * @param array<string, mixed> $identity
 */
function turn_loop($atom, SqlBridge $bridge, array $identity)
{
    $serializer = new Serializer();
    $result = null;

    while (true) {
        // The park is guarded, and this is the load-bearing guard of the whole
        // runtime. `host_park()` throws a \RuntimeException on three paths that
        // client-controlled data reaches:
        //
        //   - the OUTBOUND json_encode() of the park request fails (a customer
        //     method returned something unencodable — mostly covered by
        //     encodable_envelope() below, this is the backstop);
        //   - the INBOUND json_decode() of the turn envelope fails, which is
        //     what a request whose args nest past PHP's json_decode() depth
        //     limit produces;
        //   - the host answers ok:false.
        //
        // Outside a try/catch, any of those unwinds turn_loop() -> activate()
        // -> php.run(), the host sees no park, and the residency is poisoned and
        // cold-booted — one cheap request could kill any Atom, repeatably. In
        // here it is just a failed turn: report it and stay parked.
        try {
            $envelope = host_park(['op' => 'turn.await', 'result' => $result]);
        } catch (\Throwable $e) {
            host_log('error', [
                'event' => 'turn_park_failed',
                'atom_type' => $identity['type'],
                'atom_id' => $identity['id'],
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            // Fixed ASCII shape, so parking THIS result cannot fail in turn.
            $result = error_envelope(
                'internal',
                'The Atoms turn boundary could not be crossed: ' . ascii_excerpt($e->getMessage()),
                get_class($e)
            );
            continue;
        }

        $kind = isset($envelope['kind']) ? $envelope['kind'] : null;

        if ($kind === 'shutdown') {
            return;
        }

        if ($kind !== 'invoke') {
            // A host-side protocol bug. Answer it and stay resident rather than
            // taking the whole residency down.
            $result = error_envelope(
                'internal',
                sprintf('turn.await resumed with unknown envelope kind %s.', var_export($kind, true)),
                null
            );
            continue;
        }

        $args = isset($envelope['args']) && is_array($envelope['args']) ? array_values($envelope['args']) : [];
        $method = isset($envelope['method']) ? $envelope['method'] : null;

        $result = encodable_envelope(
            run_turn($atom, $serializer, $bridge, $identity, $method, $args),
            $method
        );
    }
}

/**
 * Activation: load everything, migrate, construct the Atom, fire onActivation,
 * then hand over to the turn loop.
 *
 * @param array<string, mixed> $cfg
 * @throws BootstrapError
 */
function activate(array $cfg)
{
    $runtimeDir = isset($cfg['paths']['runtime']) ? (string) $cfg['paths']['runtime'] : RUNTIME_DIR_DEFAULT;
    $coreDir = isset($cfg['paths']['core']) ? (string) $cfg['paths']['core'] : CORE_DIR_DEFAULT;

    // atoms-core first: BridgeDatabase implements Atoms\Database and
    // CfAtomContext implements Atoms\Runtime\AtomContext, so those interfaces
    // must exist before the prelude classes are declared.
    require_all($coreDir, core_files());
    require_all($runtimeDir, runtime_files());

    if (!isset($cfg['atom']['type']) || !is_string($cfg['atom']['type']) || $cfg['atom']['type'] === '') {
        throw new BootstrapError('internal', '$CFG.atom.type is missing.');
    }
    if (!isset($cfg['atom']['id']) || !is_string($cfg['atom']['id'])) {
        throw new BootstrapError('internal', '$CFG.atom.id is missing.');
    }

    $type = $cfg['atom']['type'];
    $id = $cfg['atom']['id'];
    $identity = ['type' => $type, 'id' => $id];

    $atoms = isset($cfg['manifest']['atoms']) && is_array($cfg['manifest']['atoms']) ? $cfg['manifest']['atoms'] : [];

    if (!isset($atoms[$type]) || !is_array($atoms[$type])) {
        throw new BootstrapError(
            'atom_not_found',
            sprintf('Atom type %s is not in the deployed bundle manifest.', $type)
        );
    }

    $entry = $atoms[$type];

    foreach (['class', 'file'] as $required) {
        if (!isset($entry[$required]) || !is_string($entry[$required]) || $entry[$required] === '') {
            throw new BootstrapError(
                'internal',
                sprintf('Manifest entry for atom type %s is missing "%s".', $type, $required)
            );
        }
    }

    // Migration files are required by MigrationEntry at apply time and must
    // never be reachable through the class autoloader.
    $migrationPaths = [];
    foreach ($atoms as $each) {
        if (isset($each['migrations']) && is_array($each['migrations'])) {
            foreach ($each['migrations'] as $path) {
                $migrationPaths[] = (string) $path;
            }
        }
    }

    $bundleFiles = isset($cfg['files']) && is_array($cfg['files']) ? array_values($cfg['files']) : [$entry['file']];
    register_bundle_autoloader($bundleFiles, $migrationPaths);

    $bridge = new SqlBridge();
    $db = new BridgeDatabase($bridge);
    $context = new CfAtomContext($db, $bridge, $identity);

    $ownMigrations = isset($entry['migrations']) && is_array($entry['migrations'])
        ? array_map('strval', array_values($entry['migrations']))
        : [];

    $applied = apply_migrations($db, $ownMigrations, $type);

    if (!is_file($entry['file'])) {
        throw new BootstrapError(
            'internal',
            sprintf('Atom source %s for type %s is missing from the guest filesystem.', $entry['file'], $type)
        );
    }

    require_once $entry['file'];

    $class = $entry['class'];

    if (!class_exists($class)) {
        throw new BootstrapError(
            'internal',
            sprintf('Atom class %s was not declared by %s.', $class, $entry['file'])
        );
    }

    if (!is_subclass_of($class, Atom::class)) {
        throw new BootstrapError(
            'internal',
            sprintf('Atom class %s does not extend %s.', $class, Atom::class)
        );
    }

    $atom = new $class($id, $context);

    LifecycleInvoker::activate($atom);

    // Same reasoning as in run_turn(): the first park is turn.await, which the
    // host refuses while a transaction is open. onActivation() is best-effort by
    // contract, so an abandoned one is rolled back and logged rather than made
    // to fail the activation — failing it would cold-boot the residency on every
    // request, forever, for a deterministic customer bug.
    settle_open_transaction($bridge, $identity, 'onActivation');

    host_log('info', [
        'event' => 'activated',
        'atom_type' => $type,
        'atom_id' => $id,
        'class' => $class,
        'migrations_applied' => $applied,
        'php_version' => PHP_VERSION,
    ]);

    turn_loop($atom, $bridge, $identity);
}

// ---------------------------------------------------------------------------
// Entry. The host's composed script defines $CFG and requires this file.
// ---------------------------------------------------------------------------

$__atoms_runtime_dir = isset($CFG['paths']['runtime']) ? (string) $CFG['paths']['runtime'] : RUNTIME_DIR_DEFAULT;

require_once rtrim($__atoms_runtime_dir, '/') . '/host.php';
require_once rtrim($__atoms_runtime_dir, '/') . '/int64.php';

try {
    if (!isset($CFG) || !is_array($CFG)) {
        throw new \RuntimeException('Atoms: the host did not define $CFG before requiring bootstrap.php.');
    }

    activate($CFG);
} catch (\Throwable $__atoms_boot_error) {
    // No turn-result envelope exists yet, so the failure is reported on the log
    // door (with its trace) and then rethrown: php.run() unwinds, and the host
    // treats the residency as poisoned per mvp-spec.md §AtomDurableObject
    // lifecycle.
    host_log('error', [
        'event' => 'activation_failed',
        'code' => $__atoms_boot_error instanceof BootstrapError ? $__atoms_boot_error->atomsCode() : 'internal',
        'atom_type' => isset($CFG['atom']['type']) ? (string) $CFG['atom']['type'] : null,
        'atom_id' => isset($CFG['atom']['id']) ? (string) $CFG['atom']['id'] : null,
        'class' => get_class($__atoms_boot_error),
        'message' => $__atoms_boot_error->getMessage(),
        'trace' => $__atoms_boot_error->getTraceAsString(),
    ]);

    throw $__atoms_boot_error;
}
