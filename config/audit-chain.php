<?php

declare(strict_types=1);

use Cbox\AuditChain\Models\AuditChainCheckpoint;
use Cbox\AuditChain\Models\AuditChainEntry;

return [

    /*
     * Where the package's own models store chains. Publish the migration with
     * `php artisan vendor:publish --tag=audit-chain-migrations` — it reads these same
     * keys, so set them BEFORE you migrate.
     *
     * A host that keeps its own tables does not use these: it points
     * `models.entry` / `models.checkpoint` at its own models (or hands its own
     * ChainModels to the chain) and states table and connection there.
     */
    'storage' => [
        'connection' => env('AUDIT_CHAIN_DB_CONNECTION'),

        'tables' => [
            'entries' => 'audit_chain_entries',
            'checkpoints' => 'audit_chain_checkpoints',
        ],

        // The column that holds a chain key's partition, on both tables.
        'partition_column' => 'partition_key',
    ],

    'models' => [
        'entry' => AuditChainEntry::class,
        'checkpoint' => AuditChainCheckpoint::class,
    ],

    /*
     * Host columns on the entry table that the default codec hashes (under `extra`).
     * An event may only write a column listed here: a stored column outside the hash
     * could be rewritten without verification noticing.
     *
     * Changing this list changes the canonical bytes of every entry that has one of
     * these columns — decide it before the first entry is written.
     */
    'codec' => [
        'extra_columns' => [],
    ],

    /*
     * The Ed25519 key that signs checkpoints. Generate one with
     * `php artisan audit-chain:keygen`.
     *
     * `public_keys` keeps RETIRED keys trusted for verification (kid => base64 public
     * key). Rotate by adding a new active key and moving the old public key here —
     * never delete one, or every checkpoint it signed stops verifying.
     */
    'signing' => [
        'key_id' => env('AUDIT_CHAIN_SIGNING_KEY_ID'),
        'secret_key' => env('AUDIT_CHAIN_SIGNING_SECRET_KEY'),
        'public_keys' => [],
    ],

    /*
     * Where signed checkpoints are exported. `null` exports nothing (the default);
     * `filesystem` writes one JSON document per checkpoint to a Laravel disk — point it
     * at an S3 or R2 bucket with object lock, written with credentials the database's
     * writers do not have.
     */
    'anchor' => [
        'driver' => env('AUDIT_CHAIN_ANCHOR', 'null'),
        'disk' => env('AUDIT_CHAIN_ANCHOR_DISK'),
        'prefix' => env('AUDIT_CHAIN_ANCHOR_PREFIX', 'audit-chain/checkpoints'),
    ],

    /*
     * `schedule` registers a daily `audit-chain:checkpoint` pass. It defaults to FALSE
     * on purpose: the first signed checkpoint is a one-way door. It attests the
     * chains' hashes as they are today, so any later re-chain (a codec change, hashing
     * ciphertext instead of plaintext to allow crypto-shredding) would make every
     * retained checkpoint report tampering. Decide whether a re-chain is ahead of you,
     * then turn it on — and if none is, turn it on now: until a chain is checkpointed,
     * deleting its newest entries is not detectable at all.
     *
     * `verify_schedule` registers a daily `audit-chain:verify --window=N` pass, which
     * only reads and is safe to enable at any time.
     */
    'checkpoint' => [
        'schedule' => env('AUDIT_CHAIN_CHECKPOINT_SCHEDULE', false),
        'time' => env('AUDIT_CHAIN_CHECKPOINT_TIME', '02:40'),
    ],

    'verify' => [
        'schedule' => env('AUDIT_CHAIN_VERIFY_SCHEDULE', false),
        'time' => env('AUDIT_CHAIN_VERIFY_TIME', '03:10'),
        'window' => env('AUDIT_CHAIN_VERIFY_WINDOW', 1000),
    ],

];
