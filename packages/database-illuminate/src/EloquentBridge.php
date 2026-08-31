<?php

declare(strict_types=1);

namespace Atoms\DatabaseIlluminate;

use Atoms\Database;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Boots illuminate/database against an Atom's own database: one
 * {@see AtomConnection} wrapped around `$db->pdo()`, registered as Eloquent's
 * default connection.
 *
 * Deliberately minimal — no Capsule, no container, no event dispatcher. An
 * Atom is single-threaded and owns exactly one database, so the whole object
 * graph is one connection and one resolver, cached per Database instance for
 * the life of the residency (the first turn pays the boot, later turns
 * reuse it).
 *
 * Eloquent's resolver is process-global: booting bridges for two different
 * Database instances alternately (only possible in tests — a deployed guest
 * hosts one Atom) repoints Model queries at whichever booted last.
 */
final class EloquentBridge
{
    public const CONNECTION_NAME = 'atom';

    /** @var array<int, AtomConnection> keyed by spl_object_id of the Database */
    private static array $connections = [];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $config extra Illuminate connection config;
     *                                     `server_version` is the documented key
     */
    public static function boot(Database $db, array $config = []): AtomConnection
    {
        $key = spl_object_id($db);

        if (isset(self::$connections[$key])) {
            return self::$connections[$key];
        }

        $connection = new AtomConnection($db->pdo(), self::CONNECTION_NAME, '', $config + [
            'driver' => 'sqlite',
            'name' => self::CONNECTION_NAME,
            'database' => self::CONNECTION_NAME,
        ]);

        $resolver = new ConnectionResolver([self::CONNECTION_NAME => $connection]);
        $resolver->setDefaultConnection(self::CONNECTION_NAME);
        Model::setConnectionResolver($resolver);

        return self::$connections[$key] = $connection;
    }

    /**
     * Forget every booted connection. For tests; a deployed guest never
     * needs it.
     */
    public static function reset(): void
    {
        self::$connections = [];
        Model::unsetConnectionResolver();
    }
}
