---
title: Run as a least-privilege database role
weight: 36
description: Let the application read and append to the chain tables and nothing else, with append-only triggers behind it
---

# Run as a least-privilege database role

Goal: the application connects as a role that can SELECT and INSERT on the chain tables
and cannot UPDATE or DELETE them, even by mistake, and even if it is compromised. Every
role, the owner included, is stopped by an append-only trigger.

## PostgreSQL

1. Use the advisory lock, so the role needs no UPDATE for locking:

   ```dotenv
   AUDIT_CHAIN_LOCK=auto   # advisory on PostgreSQL
   ```

2. Migrate as the owner, then print and apply the grants as the owner:

   ```bash
   php artisan migrate                              # as the owner role
   php artisan audit-chain:grants app_runtime > grants.sql
   psql "$OWNER_DSN" -v ON_ERROR_STOP=1 -f grants.sql
   ```

3. Point the app at the runtime role (`DB_USERNAME=app_runtime`), or keep the owner for
   migrations and give the chain its own connection:

   ```php
   // config/database.php
   'audit' => [...config('database.connections.pgsql'), 'username' => env('AUDIT_DB_USERNAME'), 'password' => env('AUDIT_DB_PASSWORD')],
   ```

   ```dotenv
   AUDIT_CHAIN_DB_CONNECTION=audit
   ```

## MySQL 8

The anchor lock (`SELECT … FOR UPDATE`) needs UPDATE on at least one column on MySQL 8.
The grants give the runtime role `UPDATE (hash)` only, and the `BEFORE UPDATE` trigger
refuses any actual update:

```bash
php artisan audit-chain:grants app_runtime --host=10.0.% > grants.sql
mysql -u root -p app < grants.sql
```

Creating triggers with binary logging enabled may need extra privileges for the account
that runs them (see MySQL's `log_bin_trust_function_creators`); the runtime role needs
none of that.

## MariaDB

MariaDB grants the anchor lock on SELECT alone. The runtime role gets `SELECT, INSERT`,
and the triggers make the tables append-only. The command prints exactly that.

## Check it

```bash
php artisan audit-chain:verify
```

As the runtime role, a manual `UPDATE audit_chain_entries SET action = 'x'` must be
refused, and so must a `DELETE`. As the owner, too: that is the trigger.

## Re-chaining later

The triggers stop the owner as well, on purpose. The one legitimate reason to rewrite
entries is a deliberate re-chain (see
[the one-way door](../core-concepts/checkpoints.md#the-first-checkpoint-is-a-one-way-door)).
`LeastPrivilegeGrants::dropAppendOnly()` gives the statements that remove the triggers;
put them back afterwards.

## Why

[Least privilege](../security/least-privilege.md) has the measured grant matrix and how it
is tested. The short version: the app role can then add to the trail and read it, and
nothing that role can do rewrites what is already there.
