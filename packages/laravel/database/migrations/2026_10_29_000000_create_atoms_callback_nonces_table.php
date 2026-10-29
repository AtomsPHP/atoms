<?php

declare(strict_types=1);

use Atoms\Laravel\Callback\DatabaseNonceStore;
use Atoms\Client\Callback\NonceStoreConfigurationException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $name = config('atoms.callback_nonce.database_connection');
        if ($name !== null && (!is_string($name) || $name === '')) {
            throw new NonceStoreConfigurationException('Set a valid callback nonce database connection name.');
        }

        return $name;
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create(DatabaseNonceStore::TABLE, static function (Blueprint $table): void {
            $table->string('nonce_hash', 64)->unique();
            $table->bigInteger('expires_at')->index('atoms_callback_nonces_expiry');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists(DatabaseNonceStore::TABLE);
    }
};
