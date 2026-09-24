<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The package's default tables. Reads `audit-chain.storage.*`, so configure the
 * connection, table names and partition column before running it.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('audit-chain.storage.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        $partition = $this->partitionColumn();

        Schema::create($this->table('entries', 'audit_chain_entries'), function (Blueprint $table) use ($partition): void {
            $table->string('id', 26)->primary();
            // NOT nullable. SQL treats NULLs as distinct in a unique index, so a NULL
            // partition would make the (partition, scope, sequence) key inert and let
            // two entries claim the same position — the chain would silently stop
            // being a chain.
            //
            // Explicit lengths throughout the unique key: InnoDB caps an index key at
            // 3072 bytes and utf8mb4 costs 4 bytes a character, so three varchar(255)
            // columns plus the sequence would be refused (error 1071).
            $table->string($partition, 191);
            $table->string('scope', 191);
            $table->unsignedBigInteger('sequence');
            $table->string('actor_type', 64);
            $table->string('actor_id')->nullable();
            $table->string('action')->index();
            $table->string('target_type', 64)->nullable();
            $table->string('target_id')->nullable();
            // Text, not a JSON column: MySQL's JSON type re-serialises what it stores.
            // The codec canonicalises what it reads anyway, but storing the exact text
            // the writer produced keeps "what was hashed" inspectable.
            $table->longText('context');
            $table->string('ip', 45)->nullable();
            $table->string('prev_hash', 64);
            $table->string('hash', 64);
            $table->timestamp('recorded_at');

            // One entry per position per chain — the constraint the append's retry
            // ladder depends on. It also serves every chain read.
            $table->unique([$partition, 'scope', 'sequence']);
        });

        Schema::create($this->table('checkpoints', 'audit_chain_checkpoints'), function (Blueprint $table) use ($partition): void {
            $table->string('id', 26)->primary();
            $table->string($partition, 191);
            $table->string('scope', 191);
            $table->unsignedBigInteger('up_to_sequence');
            $table->string('root_hash', 64);
            $table->text('signature');
            $table->timestamps();

            $table->index([$partition, 'scope', 'up_to_sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('checkpoints', 'audit_chain_checkpoints'));
        Schema::dropIfExists($this->table('entries', 'audit_chain_entries'));
    }

    private function table(string $key, string $default): string
    {
        $table = config('audit-chain.storage.tables.'.$key);

        return is_string($table) && $table !== '' ? $table : $default;
    }

    private function partitionColumn(): string
    {
        $column = config('audit-chain.storage.partition_column');

        return is_string($column) && $column !== '' ? $column : 'partition_key';
    }
};
