<?php

declare(strict_types=1);

namespace Atoms\Symfony\Migrations;

use Atoms\Symfony\Callback\DoctrineNonceStore;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029000000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        // These platforms commit DDL implicitly.
        return !$this->platform instanceof AbstractMySQLPlatform && !$this->platform instanceof OraclePlatform;
    }

    public function getDescription(): string
    {
        return 'Create shared callback nonce storage.';
    }

    public function up(Schema $schema): void
    {
        $table = new Table(DoctrineNonceStore::TABLE);
        $table->addColumn('nonce_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('expires_at', Types::BIGINT, ['notnull' => true]);
        $table->addUniqueIndex(['nonce_hash'], 'atoms_callback_nonces_nonce_hash_unique');
        $table->addIndex(['expires_at'], 'atoms_callback_nonces_expiry');

        foreach ($this->platform->getCreateTableSQL($table) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL(DoctrineNonceStore::TABLE));
    }
}
