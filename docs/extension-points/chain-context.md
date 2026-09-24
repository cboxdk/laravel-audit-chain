---
title: Chain context
weight: 44
description: Run checkpoint and verify sweeps inside each chain's own tenant context
---

# Chain context

```php
interface ChainContext
{
    /** @template T @param Closure(): T $callback @return T */
    public function run(ChainKey $key, Closure $callback): mixed;
}
```

`Checkpointer` and `ChainVerifier` sweep every chain in one process. They call
`ChainContext::run()` around each chain. The default just runs the callback.

Bind your own when something about a chain depends on ambient state:

- signing keys that are per tenant, resolved from the current tenant;
- a checkpoint model with a tenant global scope, or a `saving` hook that checks the
  row's tenant against the current one;
- a database connection or search path chosen per tenant.

```php
class TenantChainContext implements ChainContext
{
    public function run(ChainKey $key, Closure $callback): mixed
    {
        // Resolve per call. Never hold a request-scoped context in a singleton.
        return app(TenantManager::class)->runAs($key->partition, $callback);
    }
}

$this->app->singleton(ChainContext::class, TenantChainContext::class);
```

Restore the previous context afterwards, even when the callback throws.
