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

## SQLite

SQLite has no row locks (`FOR UPDATE` compiles to nothing) and serialises writers on the
whole database, so the race cannot happen there. The suite still covers every branch of
the ladder on SQLite by injecting the interleavings directly; the forking test needs a
server engine. See [Testing](../getting-started/testing.md).
