<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Tests\Fixtures;

use Cbox\AuditChain\Testing\InteractsWithAuditChain;

/**
 * Composition site so the shippable InteractsWithAuditChain trait is type-checked.
 */
final class AuditChainHarness
{
    use InteractsWithAuditChain;
}
