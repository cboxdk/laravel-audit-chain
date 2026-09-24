<?php

declare(strict_types=1);

use Cbox\AuditChain\Exceptions\InvalidChainEvent;
use Cbox\AuditChain\Exceptions\InvalidChainKey;
use Cbox\AuditChain\ValueObjects\ChainActor;
use Cbox\AuditChain\ValueObjects\ChainEvent;
use Cbox\AuditChain\ValueObjects\ChainKey;

it('refuses a NUL byte in either half', function (string $partition, string $scope): void {
    expect(fn () => ChainKey::of($partition, $scope))->toThrow(InvalidChainKey::class);
})->with([
    'nul in partition' => ["a\0b", 'scope'],
    'nul in scope' => ['partition', "a\0b"],
]);

it('accepts an empty half — a single-tenant app needs no partition', function (): void {
    $key = ChainKey::of('', 'app');

    expect($key->partition)->toBe('')
        ->and($key->id())->not->toBe(ChainKey::of('app', '')->id());
});

it('gives every distinct pair a distinct id, even where describe() cannot', function (): void {
    $a = ChainKey::of('a/b', 'c');
    $b = ChainKey::of('a', 'b/c');

    expect($a->describe())->toBe($b->describe())
        ->and($a->id())->not->toBe($b->id())
        ->and($a->equals($b))->toBeFalse()
        ->and($a->equals(ChainKey::of('a/b', 'c')))->toBeTrue();
});

it('builds events fluently without mutating the original', function (): void {
    $base = ChainEvent::by(ChainActor::of('user', 'usr_1'), 'invoice.voided', ['reason' => 'dup']);
    $full = $base->on('invoice', 'inv_9')->from('203.0.113.4')->withColumns(['request_id' => 'r1']);

    expect($base->targetType)->toBeNull()
        ->and($full->targetType)->toBe('invoice')
        ->and($full->targetId)->toBe('inv_9')
        ->and($full->ip)->toBe('203.0.113.4')
        ->and($full->columns)->toBe(['request_id' => 'r1'])
        ->and($full->actor->id)->toBe('usr_1')
        ->and(ChainEvent::system('x')->actor->type)->toBe(ChainActor::SYSTEM);
});

it('refuses an actor without a type', function (): void {
    expect(fn () => ChainActor::of(''))->toThrow(InvalidChainEvent::class);
});
