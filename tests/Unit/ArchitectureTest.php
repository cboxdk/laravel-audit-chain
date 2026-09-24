<?php

declare(strict_types=1);

use Cbox\AuditChain\Contracts\AuditChain;
use Cbox\AuditChain\Contracts\ChainContext;
use Cbox\AuditChain\Contracts\ChainInventory;
use Cbox\AuditChain\Contracts\CheckpointAnchor;
use Cbox\AuditChain\Contracts\CheckpointSigner;
use Cbox\AuditChain\Contracts\EntryCodec;
use Cbox\AuditChain\Exceptions\AuditChainException;
use Symfony\Component\Finder\Finder;

/**
 * @return list<class-string>
 */
function packageClasses(string $subdirectory = ''): array
{
    $root = dirname(__DIR__, 2).'/src';
    $classes = [];

    foreach (Finder::create()->files()->in($root.($subdirectory === '' ? '' : '/'.$subdirectory))->name('*.php') as $file) {
        $relative = substr((string) $file->getRealPath(), strlen((string) realpath($root)) + 1, -4);
        $class = 'Cbox\\AuditChain\\'.str_replace('/', '\\', $relative);

        if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/*
 * OPENNESS. A consumer must be able to extend, decorate, fake or mock anything this
 * package ships, so nothing is sealed. (Enums are final by nature and need no keyword.)
 */
it('seals no class', function (): void {
    $final = array_values(array_filter(
        packageClasses(),
        static fn (string $class): bool => class_exists($class) && ! enum_exists($class) && (new ReflectionClass($class))->isFinal(),
    ));

    expect(packageClasses())->not->toBeEmpty()
        ->and($final)->toBe([]);
});

it('keeps Contracts interface-only', function (): void {
    $notInterfaces = array_values(array_filter(packageClasses('Contracts'), static fn (string $class): bool => ! interface_exists($class)));

    expect($notInterfaces)->toBe([]);
});

it('binds a default for every contract', function (string $contract): void {
    expect(app($contract))->toBeInstanceOf($contract);
})->with([AuditChain::class, EntryCodec::class, CheckpointSigner::class, CheckpointAnchor::class, ChainContext::class, ChainInventory::class]);

it('marks every exception it throws as its own', function (): void {
    $unmarked = array_values(array_filter(
        packageClasses('Exceptions'),
        static fn (string $class): bool => class_exists($class) && ! is_subclass_of($class, AuditChainException::class),
    ));

    expect($unmarked)->toBe([]);
});
