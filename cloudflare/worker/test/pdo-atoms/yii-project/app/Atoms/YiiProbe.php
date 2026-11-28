<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Atoms\YiiProbe\Support\YiiSmoke;
use Atoms\Atom;
use PDO;
use yii\db\Connection;

final class YiiProbe extends Atom
{
    private Connection $yiiConnection;
    private PDO $activationPdo;
    private int $cacheReads = 0;
    private PDO $originalYiiPdo;

    protected function onActivation(): void
    {
        $this->activationPdo = new PDO('atoms:');
        $this->yiiConnection = YiiSmoke::open('atoms:');
        $this->originalYiiPdo = $this->yiiConnection->pdo;
    }

    public function cachedValues(?int $value = null): array
    {
        $db = $this->yiiConnection;
        $db->open(); // Yii's normal cache lookup on this same Connection.
        if ($value !== null) {
            $db->createCommand("INSERT INTO probe_rows (key, value) VALUES ('identity', :value)")
                ->bindValue(':value', $value, PDO::PARAM_INT)
                ->execute();
        }
        $sql = "SELECT value FROM probe_rows WHERE key = 'identity'";

        return [
            'id' => $this->id,
            'read' => ++$this->cacheReads,
            'samePdo' => $this->originalYiiPdo === $db->pdo,
            'objectIds' => [spl_object_id($db), spl_object_id($db->pdo), spl_object_id($this->activationPdo)],
            'yiiValue' => $db->createCommand($sql)->queryScalar(),
            'nativeValue' => $this->activationPdo->query($sql)->fetchColumn(),
        ];
    }

    public function yii(): array
    {
        return YiiSmoke::run($this->yiiConnection, fn (): array => $this->db()->query(
            'SELECT value FROM probe_rows WHERE key = ?', ['yii']
        ));
    }
}
