---
title: Testing
weight: 12
description: Fake the audit chain in your tests, and run this package's suite against a real database engine
---

# Testing

## Faking the chain in your application's tests

`Cbox\AuditChain\Testing\InteractsWithAuditChain` swaps the bound `AuditChain` for an
in-memory `FakeAuditChain`, in the spirit of `Event::fake()`:

```php
use Cbox\AuditChain\Testing\InteractsWithAuditChain;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;

uses(InteractsWithAuditChain::class);

it('audits a voided invoice', function () {
    $audit = $this->fakeAuditChain();

    app(InvoiceService::class)->void($invoice, $user);

    $audit->assertRecorded('invoice.voided');
    $audit->assertRecorded('invoice.voided', fn (ChainEvent $event, ChainKey $key) =>
        $event->actor->id === $user->id && $key->partition === $invoice->tenant_id);
    $audit->assertRecordedOn(ChainKey::of($invoice->tenant_id, 'billing'), 'invoice.voided');
    $audit->assertNotRecorded('invoice.deleted');
    $audit->assertRecordedCount(1);
});
```

The fake is a real chain that is not stored: entries get sequences, `prev_hash` and
`hash` from the same codec and hash composition as the database chain, so code that
reads them sees realistic values. `verify()` always reports intact (nothing stored can
have been tampered with). `checkpoint()` records the checkpoint for
`assertCheckpointed()` without signing anything.

This package's own suite uses the same trait (`tests/TestCase.php`).

## Running this package's suite

```bash
composer install
vendor/bin/pest
```

By default it runs on in-memory SQLite. SQLite cannot run two writers at once, so the
test that forks eight processes appending to one chain skips itself there. Every other
concurrency test injects the exact interleaving a race produces, so every branch of the
retry ladder is covered on SQLite too.

### Against PostgreSQL, MySQL or MariaDB

Point the suite at a server with `DB_CONNECTION` and the usual `DB_*` variables, and
the whole suite runs against it, forking test included (it also needs `ext-pcntl`):

```bash
docker run -d --rm --name audit-pg -p 55432:5432 \
  -e POSTGRES_DB=audit_chain -e POSTGRES_USER=audit_chain -e POSTGRES_PASSWORD=secret \
  postgres:16

DB_CONNECTION=pgsql DB_PORT=55432 DB_DATABASE=audit_chain \
DB_USERNAME=audit_chain DB_PASSWORD=secret vendor/bin/pest
```

The same works with `DB_CONNECTION=mysql` (`mysql:8.4`) and `DB_CONNECTION=mariadb`
(`mariadb:11.8`) on port 3306. On a server engine the suite migrates once per process
and wraps each test in a transaction, so a run takes seconds, not minutes.

Two more knobs for server engines:

- `DB_ISOLATION_LEVEL` (e.g. `"repeatable read"`) sets the session isolation level, which
  is how the [isolation matrix](../core-concepts/concurrency.md#isolation-levels-measured)
  was measured.
- `DB_ADMIN_USERNAME` / `DB_ADMIN_PASSWORD` name an account that may create users, so
  `tests/Feature/LeastPrivilegeTest.php` can run on MySQL and MariaDB (`root` in the
  official images). On PostgreSQL it uses the suite's own account, which is a superuser in
  the official image. Without it those tests skip on MySQL and MariaDB.

`.github/workflows/ci.yml` runs exactly this for all three engines in its `engines` job.
