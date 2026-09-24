<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only schema. Kept in a migration rather than created inside a test because
 * MySQL and MariaDB commit implicitly on DDL, which would end the transaction
 * RefreshDatabase wraps each test in and leak rows between tests.
 *
 * - `audit_chain_entries.request_id`: a host column, for the extra-columns tests.
 * - `legacy_audit_logs` / `legacy_audit_checkpoints`: a chain written by an earlier
 *   implementation, with its own partition column and an extra hashed column, for the
 *   "adopt an existing chain" tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_chain_entries', function (Blueprint $table): void {
            $table->string('request_id')->nullable();
        });

        Schema::create('legacy_audit_logs', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 64);
            $table->string('scope');
            $table->string('organization_id', 26)->nullable();
            $table->unsignedBigInteger('sequence');
            $table->string('actor_type');
            $table->string('actor_id')->nullable();
            $table->string('action');
            $table->string('target_type', 64)->nullable();
            $table->string('target_id')->nullable();
            $table->json('context');
            $table->string('ip')->nullable();
            $table->string('prev_hash', 64);
            $table->string('hash', 64);
            $table->timestamp('recorded_at');

            $table->unique(['environment_id', 'scope', 'sequence']);
        });

        Schema::create('legacy_audit_checkpoints', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 64);
            $table->string('scope');
            $table->string('organization_id', 26)->nullable();
            $table->unsignedBigInteger('up_to_sequence');
            $table->string('root_hash', 64);
            $table->text('signature');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_audit_checkpoints');
        Schema::dropIfExists('legacy_audit_logs');

        Schema::table('audit_chain_entries', function (Blueprint $table): void {
            $table->dropColumn('request_id');
        });
    }
};
