---
title: Concurrency
weight: 24
description: How parallel appends to one chain stay gapless and lossless on PostgreSQL, MySQL and MariaDB, and what was measured
---

# Concurrency

Appending is the one place where requests race for the same value: the next sequence
of a chain. Two writers must never compute the same position, and a writer that loses
the race must not lose its entry. `DatabaseAuditChain::record()` handles both. Every
part of the design below was measured on PostgreSQL 16, MySQL 8.4 and MariaDB 11.8 with
eight forked processes appending 100 entries each to one chain, and the forking test
that measured it ships in the suite.

The unique `(partition, scope, sequence)` key is what makes the chain **safe**: two
writers that compute the same position cannot both store it, and the loser re-reads and
retries. The lock is what makes it **live**: without one, eight writers collide on nearly
every attempt and run out of retries. How appenders lock is a strategy (a `ChainLock`),
covered in [Lock strategies](#lock-strategies) below. Sections 1 to 4 describe the default,
the anchor lock.

## 1. Serialise on the anchor, not the head

Appenders lock the chain's **first** entry (sequence 1, the "anchor") and only then read
the head.

The obvious alternative, `SELECT … ORDER BY sequence DESC LIMIT 1 FOR UPDATE`, is wrong
under READ COMMITTED: a blocked `FOR UPDATE` re-checks its predicate against the row it
was waiting for, not against the `ORDER BY … LIMIT 1` that picked it. A waiter wakes up
holding the old head and computes a sequence that was just taken. On PostgreSQL 16 that
lost 700 of 800 appends. The anchor's predicate is an equality on the unique key, so it
cannot go stale: whoever holds it reads the head afterwards and sees the previous
holder's committed entry. 800 of 800.

## 2. Catch the duplicate key and retry

`unique(partition, scope, sequence)` is the backstop. A violation means another writer
took the position, and the append re-reads the head and tries again. That covers the
one window the anchor cannot: the very first entry of a chain, when there is no anchor
to lock yet. It converges in one extra round.

Laravel's `DB::transaction(…, attempts: 3)` does not help here: it retries SQLSTATE
40001 and deadlocks, and a duplicate key is neither. Without the ladder, the entry is
simply lost.

## 3. Retry serialisation failures on the same ladder

On an empty chain, a locking read that matches nothing makes InnoDB take a **gap lock**.
Eight processes opening a new chain each hold that gap, and each needs an
insert-intention lock inside the gap the other seven hold. MariaDB 11.8 resolved that
pile-up as SQLSTATE 40001 instead of a duplicate key: 6 of 800 appends lost.

So:

- the anchor is **found** with a plain read, and **locked** separately by primary key.
  An exact primary-key match takes a record lock and can never take a gap lock. On an
  empty chain nothing is locked at all, and the unique key plus the retry decide the
  race;
- serialisation failures (SQLSTATE 40001, deadlock) retry on the same ladder as
  duplicate keys, up to 8 attempts, with a jittered, growing pause (capped at 64 ms) so
  retriers do not collide again in lockstep.

Each attempt is its own transaction (`attempts: 1`), so there is one retry ladder, not
Laravel's immediate, pause-less one nested inside it.

## 4. Find the anchor outside the transaction

MySQL and MariaDB default to REPEATABLE READ, where a transaction's **first** consistent
read fixes the snapshot every later consistent read is answered from. If the anchor
search ran inside the transaction, a waiter that then blocked on the anchor would wake
up and read the head from a snapshot taken before it got the lock: the stale head the
anchor exists to prevent. Measured on MariaDB: the retry budget was exhausted on
duplicate keys hundreds of times. With the search outside, the first statement in the
transaction is the anchor's locking read (locking reads see the latest committed row),
the head read after it establishes the snapshot, and it sees the previous holder's
commit. 800 of 800 on all three engines.

## What the retry does not cover

- **Inside your own transaction.** The attempt becomes a savepoint, so a duplicate key
  still rolls back to it and retries. A serialisation failure does not and must not:
  the engine has already rolled your whole transaction back, so Laravel raises a
  `DeadlockException` and it reaches you, the only one who can retry the unit of work.
- **REPEATABLE READ after you already read the table.** If your transaction read the
  entry table before calling `record()`, a retry re-reads the head from your fixed
  snapshot and collides again. That ends in `CannotAppendToChain`, loud, rather than a
  lost or misplaced entry.
- **An exhausted budget.** `CannotAppendToChain` is thrown, never swallowed: a hole in
  the trail is indistinguishable from a deletion.

## Lock strategies

`audit-chain.lock.driver` (env `AUDIT_CHAIN_LOCK`) picks how appenders to one chain are
serialised. The chain's own logic (head read, insert, retry ladder) is the same under
all of them.

| Strategy | How | Engines | Privileges the runtime role needs |
|---|---|---|---|
| `anchor` (default) | `SELECT … FOR UPDATE` on the chain's first entry, by primary key | all | PostgreSQL and MySQL 8: UPDATE on one column. MariaDB: none beyond SELECT |
| `advisory` | `pg_advisory_xact_lock(key)` per chain | PostgreSQL only; refused elsewhere | none beyond SELECT and INSERT |
| `auto` | `advisory` on PostgreSQL, `anchor` elsewhere | all | as above |

The anchor lock is the default because it is what every chain so far was written under
and what the numbers above were measured with. On PostgreSQL, prefer `advisory` (or
`auto`): it needs no table privilege, and it holds up at every isolation level (below).
[Least privilege](../security/least-privilege.md) has the grants.

### The advisory lock

`Locking\PostgresAdvisoryChainLock` opens the attempt's transaction and, as its first
statement, takes a **transaction-scoped** advisory lock whose key is derived from the
chain:

```
key = first 8 bytes of SHA-256("cbox/audit-chain/advisory/v1" \0 table \0 partition \0 scope),
      read as a big-endian signed 64-bit integer
```

- **Collisions only over-serialise.** Two different chains whose keys collide (or a chain
  whose key collides with an advisory lock your own application takes on the same
  database) share a lock: appends to them wait for each other. They never corrupt
  anything, because correctness never rested on the lock; the unique key and the retry
  ladder still decide every position. With 64-bit keys a collision between two of your
  chains is vanishingly unlikely. If your application uses single-`bigint` advisory locks
  of its own, a clash is possible in principle and costs only waiting.
- **Released by PostgreSQL, never by the package.** A transaction-scoped lock ends when
  the transaction ends, commit or rollback, so a crashed worker cannot leave a chain
  locked. It is also safe behind a transaction-pooling proxy such as PgBouncer, which a
  session-level lock would not be.
- **Nested in your transaction**, the append runs as a savepoint and the lock is held until
  **your** transaction ends. A second appender to the same chain waits until your commit,
  which is exactly when your entry becomes visible to it. (The anchor lock behaves the same
  way.) Two transactions that each append to chains A and B in opposite orders can
  deadlock; PostgreSQL detects it and aborts one with SQLSTATE 40P01, which reaches you,
  as with any lock.
- **Isolation.** When the append owns its transaction, the first statement pins it to
  `READ COMMITTED` (`SET TRANSACTION ISOLATION LEVEL READ COMMITTED`, which takes no
  snapshot). That is the isolation the append was designed for, and it is what lets the
  advisory lock hold up under a session default of `REPEATABLE READ` or `SERIALIZABLE`.
  Without it, the lock statement itself fixes the transaction's snapshot **before** it
  waits, the head read afterwards is stale, and contended appends exhaust their retries.
  Nested in your transaction the append cannot change your isolation level; see below.

Every process appending to the same chains must use the same strategy: an anchor-locker
and an advisory-locker do not exclude each other. Mixing them is still safe (the unique key
decides), but under contention it costs retries, and possibly `CannotAppendToChain`. A
rolling deploy that switches strategy has a short window of that, nothing worse.

### Isolation levels, measured

Eight writers x 100 appends to one chain, each engine with its session default set to
each level (`DB_ISOLATION_LEVEL`, see [Testing](../getting-started/testing.md)):

| Engine | Strategy | READ COMMITTED | REPEATABLE READ | SERIALIZABLE |
|---|---|---|---|---|
| PostgreSQL 16 | anchor | 800/800 (engine default) | **fails**: retries exhausted | **fails**: retries exhausted |
| PostgreSQL 16 | advisory | 800/800 | 800/800 | 800/800 |
| MySQL 8.4 | anchor | 800/800 | 800/800 (engine default) | 800/800 |
| MariaDB 11.8 | anchor | 800/800 | 800/800 (engine default) | **fails**: 2 of 800 |

Every failure is loud: `CannotAppendToChain`, never a lost or misplaced entry. So:

- **PostgreSQL with the anchor lock requires READ COMMITTED**, PostgreSQL's default. Under
  REPEATABLE READ or SERIALIZABLE the locking read fixes the snapshot before it waits, so
  the head read after it is stale. Use the advisory lock, which pins READ COMMITTED for its
  own transaction.
- **MariaDB under SERIALIZABLE** turns plain reads into shared locks, and some appends
  deadlock past the retry budget. Use the default REPEATABLE READ or READ COMMITTED.
- **Nested in your transaction**, no strategy can change your isolation: an append inside a
  REPEATABLE READ transaction that has already read the entry table sees your snapshot
  (see [What the retry does not cover](#what-the-retry-does-not-cover)).

## SQLite

SQLite has no row locks (`FOR UPDATE` compiles to nothing) and serialises writers on the
whole database, so the race cannot happen there. The suite still covers every branch of
the ladder on SQLite by injecting the interleavings directly; the forking test needs a
server engine. See [Testing](../getting-started/testing.md).
