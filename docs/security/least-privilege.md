---
title: Least privilege
weight: 52
description: Run the app as a database role that can only read and append — the exact grants each lock strategy needs, per engine, and how they are tested
---

# Least privilege

The chain is tamper-evident, and it is worth more if the application's own database role
cannot tamper at all: no UPDATE, no DELETE on the chain tables. This page gives the exact
grants for that, per engine and per [lock strategy](../core-concepts/concurrency.md#lock-strategies).

## The catch: a row lock is a write privilege

The default **anchor** lock serialises appenders with `SELECT … FOR UPDATE` on the
chain's first entry. Two of the three engines treat that as a write:

| Runtime role holds SELECT and INSERT only | PostgreSQL 16 | MySQL 8.4 | MariaDB 11.8 |
|---|---|---|---|
| Anchor lock (`SELECT … FOR UPDATE`) | **refused**, SQLSTATE 42501 | **refused**, error 1142 | allowed |
| …with `UPDATE` on one column added | allowed | allowed | – |
| Advisory lock (`pg_advisory_xact_lock`) | allowed, no privilege needed | – | – |

PostgreSQL refuses every row-lock mode (`FOR UPDATE`, `FOR NO KEY UPDATE`, `FOR SHARE`,
`FOR KEY SHARE`) to a role with no UPDATE on at least one column of the table. MySQL 8
refuses a locking read without UPDATE (one column is enough), DELETE or LOCK TABLES.
The symptom is precise: **a chain's first append succeeds** (there is no anchor to lock
yet) **and every later one is refused**.

## The grant matrix

| Engine | Lock strategy | Runtime role gets | Plus, for every role |
|---|---|---|---|
| PostgreSQL | `advisory` (or `auto`) | `SELECT, INSERT` on both tables | append-only triggers |
| PostgreSQL | `anchor` | `SELECT, INSERT` on both tables, `UPDATE ("hash")` on the entry table | append-only triggers |
| MySQL 8 | `anchor` | `SELECT, INSERT` on both tables, ``UPDATE (`hash`)`` on the entry table | append-only triggers |
| MariaDB | `anchor` | `SELECT, INSERT` on both tables | append-only triggers |

The `UPDATE ("hash")` grant exists only so the row lock is allowed. The append-only
trigger makes it unusable for an actual UPDATE: it refuses UPDATE and DELETE (and TRUNCATE
on PostgreSQL) on both tables **for every role, the table owner included**. On PostgreSQL
the advisory strategy removes the extra grant altogether, which is the recommended setup
there.

A MySQL alternative to the column grant is `LOCK TABLES` on the schema. It is not
recommended: it lets the role take a table-wide write lock and block everyone else.

## Getting the SQL

```bash
php artisan audit-chain:grants app_runtime               # for the configured lock strategy
php artisan audit-chain:grants app_runtime --lock=anchor
php artisan audit-chain:grants app_runtime --host=10.0.%  # MySQL/MariaDB account host
```

It prints, and never runs, the triggers and grants for this app's connection, table
names and lock strategy. Run them as the tables' owner (PostgreSQL), or as an account that
may create triggers and grant (MySQL, MariaDB). On PostgreSQL with the advisory lock it
prints:

```sql
CREATE OR REPLACE FUNCTION "audit_chain_refuse_change"() RETURNS trigger LANGUAGE plpgsql AS $audit_chain$
BEGIN
    RAISE EXCEPTION 'audit chain rows are append-only: % on % refused', TG_OP, TG_TABLE_NAME
        USING ERRCODE = 'insufficient_privilege';
END
$audit_chain$;
DROP TRIGGER IF EXISTS "audit_chain_entries_append_only" ON "audit_chain_entries";
CREATE TRIGGER "audit_chain_entries_append_only" BEFORE UPDATE OR DELETE ON "audit_chain_entries" FOR EACH ROW EXECUTE FUNCTION "audit_chain_refuse_change"();
DROP TRIGGER IF EXISTS "audit_chain_entries_no_truncate" ON "audit_chain_entries";
CREATE TRIGGER "audit_chain_entries_no_truncate" BEFORE TRUNCATE ON "audit_chain_entries" FOR EACH STATEMENT EXECUTE FUNCTION "audit_chain_refuse_change"();
-- …the same two triggers on "audit_chain_checkpoints"…
GRANT SELECT, INSERT ON "audit_chain_entries" TO "app_runtime";
GRANT SELECT, INSERT ON "audit_chain_checkpoints" TO "app_runtime";
```

In code, `Cbox\AuditChain\Security\LeastPrivilegeGrants` returns the same statements.
`dropAppendOnly()` returns the statements that remove the triggers, for the one legitimate
reason to rewrite entries: a deliberate re-chain.

## What else the role needs

- To **connect** and to **use the schema** (`USAGE` on the schema on PostgreSQL, which
  `PUBLIC` has on `public` by default).
- Nothing for migrations: run them as the owner, not as the runtime role.
- Nothing more for checkpoints, verification or the commands: they only SELECT and INSERT.

## How this is tested

`tests/Feature/LeastPrivilegeTest.php` creates the runtime role on a real engine, applies
exactly the statements above, and then works **as that role**:

- appends to several chains (inside and outside a caller's transaction), checkpoints and
  verifies them, under every strategy the engine supports;
- tries to UPDATE and DELETE entries and checkpoints, and is refused (by the missing grant,
  or by the trigger behind the one column it may "update");
- checks that the trigger also stops the table owner;
- reproduces the bug: the anchor lock with the column grant withheld, on PostgreSQL
  (42501) and MySQL (1142), and that MariaDB does not ask for it;
- runs the 8-writer x 100-append forking test as the runtime role: 800 written, gapless,
  verifying.

It runs on PostgreSQL with the suite's own (superuser) account, and on MySQL and MariaDB
when `DB_ADMIN_USERNAME` / `DB_ADMIN_PASSWORD` name an account that may create users. See
[Testing](../getting-started/testing.md).

Measured on PostgreSQL 16, MySQL 8.4 and MariaDB 11.8. Other versions may differ: MariaDB
in particular may start asking for a row-lock grant, and the test says so if it does.
