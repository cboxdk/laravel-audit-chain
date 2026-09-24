---
title: Schedule checkpoints and verification
weight: 34
description: The two opt-in scheduled passes, what each costs, and the order to turn them on
---

# Schedule checkpoints and verification

Installing the package schedules nothing. There are two opt-in passes, registered on
Laravel's scheduler when their flag is `true`. Your application still needs
`php artisan schedule:run` every minute, as usual.

## Verification: safe to turn on any time

```dotenv
AUDIT_CHAIN_VERIFY_SCHEDULE=true
AUDIT_CHAIN_VERIFY_TIME=03:10
AUDIT_CHAIN_VERIFY_WINDOW=1000
```

Runs `audit-chain:verify --window=1000` daily. It only reads. The window re-hashes the
newest 1000 entries of each chain, checks their link into the older part, and checks
the newest checkpoint, which catches recent tampering and truncation cheaply. Run a
full pass (`audit-chain:verify` with no window) on a slower cadence, from your own
scheduler entry or by hand; it re-reads everything.

The command exits non-zero when any chain is broken. Hook your scheduler's failure
notifications (`->emailOutputOnFailure()`, `->onFailure()`) up to someone who will act.

## Checkpoints: decide first, then turn on

```dotenv
AUDIT_CHAIN_CHECKPOINT_SCHEDULE=true
AUDIT_CHAIN_CHECKPOINT_TIME=02:40
```

Runs `audit-chain:checkpoint` daily, signing every chain that advanced since its last
checkpoint. Chains that did not advance cost nothing.

Read [the one-way door](../core-concepts/checkpoints.md#the-first-checkpoint-is-a-one-way-door)
first. If a re-chain is ahead of you, do it before the first checkpoint. If none is,
turn this on now: until a chain has a checkpoint, deleting its newest entries is not
detectable.

Both passes use `withoutOverlapping()`. A malformed time falls back to the default
rather than leaving a pass unscheduled.

## Your own schedule instead

Leave both flags off and schedule the commands yourself for full control:

```php
Schedule::command('audit-chain:checkpoint')->hourly()->withoutOverlapping();
Schedule::command('audit-chain:verify --window=500')->everySixHours();
Schedule::command('audit-chain:verify')->weekly()->sundays()->at('04:00');
```
