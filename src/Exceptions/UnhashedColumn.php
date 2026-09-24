<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Exceptions;

use InvalidArgumentException;

/**
 * An event asked to write a column the codec does not hash, or one the chain itself
 * owns. Refused before anything is written: a stored column outside the hash could be
 * rewritten later without verification noticing.
 */
class UnhashedColumn extends InvalidArgumentException implements AuditChainException
{
    /**
     * @param  list<string>  $declared
     */
    public static function notDeclared(string $column, string $codec, array $declared): self
    {
        $list = $declared === [] ? 'none' : implode(', ', $declared);

        return new self("Refusing to write column [{$column}]: the entry codec [{$codec}] does not hash it (it declares: {$list}). Declare it in the codec's extraColumns() so it is covered by the chain.");
    }

    public static function reserved(string $column): self
    {
        return new self("Refusing to write column [{$column}]: it is maintained by the chain itself.");
    }
}
