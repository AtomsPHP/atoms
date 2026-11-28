<?php

declare(strict_types=1);

namespace App\Atoms\YiiProbe\Support;

use PDO;
use yii\BaseYii;
use yii\db\Connection;

final class YiiSmoke
{
    public static function open(string $dsn): Connection
    {
        $db = new Connection([
            'dsn' => $dsn,
            'driverName' => 'sqlite',
            'enableLogging' => false,
            'enableProfiling' => false,
        ]);
        $db->open();

        return $db;
    }

    public static function run(Connection $db, callable $observe): array
    {
        $tx = $db->beginTransaction();
        $db->createCommand('INSERT INTO probe_rows (key, value) VALUES (:key, :value)')
            ->bindValue(':key', 'yii', PDO::PARAM_STR)
            ->bindValue(':value', 42, PDO::PARAM_INT)
            ->execute();
        $count = $db->createCommand('SELECT COUNT(*) FROM probe_rows WHERE key = :key')
            ->bindValue(':key', 'yii', PDO::PARAM_STR);
        $before = $count->queryScalar();
        $pending = $observe();
        $tx->rollBack();

        return [BaseYii::getVersion(), $before, $count->queryScalar(), $pending];
    }
}
