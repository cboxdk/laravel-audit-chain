<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Testing;

use Cbox\AuditChain\Contracts\AuditChain;

/**
 * Swap the audit chain for an assertable fake, `Event::fake()`-style:
 *
 *     $audit = $this->fakeAuditChain();
 *     // ... exercise code ...
 *     $audit->assertRecorded('invoice.voided');
 */
trait InteractsWithAuditChain
{
    protected function fakeAuditChain(): FakeAuditChain
    {
        $fake = new FakeAuditChain;

        app()->instance(AuditChain::class, $fake);

        return $fake;
    }
}
