<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Exceptions;

use Throwable;

/**
 * Implemented by every exception this package throws, so a host can catch the
 * package's failures as one family.
 */
interface AuditChainException extends Throwable {}
