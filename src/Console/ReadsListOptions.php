<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Console;

use Cbox\AuditChain\ValueObjects\ChainFilter;
use Illuminate\Console\Command;

/**
 * @mixin Command
 */
trait ReadsListOptions
{
    protected function chainFilter(): ChainFilter
    {
        return new ChainFilter($this->listOption('partition'), $this->listOption('scope'));
    }

    /**
     * @return list<string>
     */
    protected function listOption(string $name): array
    {
        $values = [];

        foreach ((array) $this->option($name) as $value) {
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }
}
