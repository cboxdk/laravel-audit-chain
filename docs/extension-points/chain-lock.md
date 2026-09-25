---
title: Chain lock
weight: 46
description: Bind your own ChainLock to change how appenders to one chain are serialised
---

# Chain lock

```php
interface ChainLock
{
    /** @template T @param Closure(): T $append @return T */
    public function withLock(ChainModels $models, ChainKey $key, Closure $append): mixed;
}
```

`withLock()` opens ONE transaction on the chain's connection
(`$models->connection()`), takes the chain's lock as the transaction's first statement,
runs `$append` (which reads the head and inserts), and returns its result.

The package ships three, selected by `audit-chain.lock.driver`: `AnchorRowChainLock`
(default), `PostgresAdvisoryChainLock` and `AutoChainLock`. See
[Concurrency](../core-concepts/concurrency.md#lock-strategies).

## Contract

- **One attempt, no retry.** Open the transaction with `attempts: 1`. The chain owns the
  single retry ladder; a second, nested one is how contenders used to exhaust their budget
  in lockstep.
- **Lock first.** The lock must be taken before `$append` reads the head, and must make
  that read see the previous appender's committed entry. Mind snapshot isolation: on
  PostgreSQL under REPEATABLE READ, any statement fixes the snapshot, so a lock taken by a
  statement that waits leaves the later head read stale.
- **Hold it until the transaction ends.** Releasing early lets the next appender read a
  head that does not yet include your uncommitted entry.
- **Refuse, don't skip.** If the lock cannot be taken on this engine, throw
  (`UnsupportedChainLock`). An append without its lock is still safe, but no longer live.
- **One strategy per chain.** Every process appending to the same chains must use the same
  strategy, or they do not exclude each other.

## Binding

```php
$this->app->singleton(ChainLock::class, MyChainLock::class);
```

That is what the container-built `DatabaseAuditChain` receives. A chain you construct
yourself takes it as its fifth argument, and defaults to the anchor lock without one.
