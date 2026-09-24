# Cbox Audit Chain

A tamper-evident, hash-chained audit trail for Laravel.

Every entry carries the SHA-256 of its own content and of the entry before it, so any
later change, deletion in the middle, or reordering is detectable. Ed25519-signed
checkpoints catch what a chain alone cannot (entries deleted off the end), and an
anchor exports each checkpoint to storage your database's writers cannot reach.

```php
use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\ValueObjects\ChainActor;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;

$chain = app(AuditChain::class);
$key = ChainKey::of($tenant->id, 'billing');

$chain->record($key, ChainEvent::by(ChainActor::of('user', $user->id), 'invoice.voided')->on('invoice', $invoice->id));

$chain->verify($key)->valid;   // false if anything recorded was changed, removed or reordered
$chain->checkpoint($key);      // sign the head, so truncation becomes detectable too
```

## What you get

- **Independent chains** addressed by `ChainKey(partition, scope)`: one per tenant, per
  ledger, per whatever you need.
- **Safe concurrent appends.** Parallel writers to one chain never lose an entry or
  share a position. Proven with eight forked writers on PostgreSQL 16, MySQL 8.4 and
  MariaDB 11.8.
- **Signed checkpoints** (Ed25519 via ext-sodium) with key rotation, and an idempotent
  `audit-chain:checkpoint` sweep.
- **Anchoring** to any Laravel disk, including S3 and Cloudflare R2.
- **`audit-chain:verify`** for full or windowed verification, with a non-zero exit on
  any break.
- **Contracts for everything**: bring your own codec, signer, anchor, tenant context or
  models, including adopting a chain another implementation wrote without rewriting it.
- **A test fake** (`FakeAuditChain`, `InteractsWithAuditChain`) with assertions.

## Honest scope

This is tamper-**evident**, not tamper-**proof**: someone who can rewrite the whole table
can recompute every hash. Only checkpoints exported to a store they cannot write stop
that. And the chain proves **integrity**, not **completeness**: it cannot know about an
event nobody recorded. See [Guarantees and limits](docs/core-concepts/guarantees.md).

## Requirements

PHP 8.4+, ext-sodium, Laravel 12 or 13. See [Requirements](docs/requirements.md).

## Installation

```bash
composer require cboxdk/laravel-audit-chain
php artisan vendor:publish --tag=audit-chain-config
php artisan vendor:publish --tag=audit-chain-migrations
php artisan migrate
php artisan audit-chain:keygen
```

Then read the [Quickstart](docs/quickstart.md).

## Documentation

- [Overview](docs/index.md) and [Quickstart](docs/quickstart.md)
- [Getting started](docs/getting-started/_index.md): installation, testing
- [Core concepts](docs/core-concepts/_index.md): architecture, hash format, checkpoints,
  concurrency, guarantees
- [Cookbook](docs/cookbook/_index.md): anchor to R2, adopt an existing chain, rotate keys,
  scheduling
- [Extension points](docs/extension-points/_index.md)
- [Configuration](docs/configuration/reference.md)
- [Security](docs/security/_index.md)

## Quality gate

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G   # level max, no baseline
vendor/bin/pest
composer audit --no-dev
composer license-check
composer sbom && git diff --exit-code sbom.json
```

## Security

Report vulnerabilities privately through GitHub Private Vulnerability Reporting. See
[SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
