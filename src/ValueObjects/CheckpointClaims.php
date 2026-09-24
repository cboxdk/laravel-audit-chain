<?php

declare(strict_types=1);

namespace Cbox\AuditChain\ValueObjects;

use Cbox\AuditChain\Contracts\CheckpointSigner;

/**
 * What a checkpoint attests: "the chain `scope` (in `partition`) had `rootHash` as the
 * hash of its entry at `upToSequence`, as of `issuedAt`".
 *
 * `partition` is nullable for one reason: a {@see CheckpointSigner} adapting an
 * existing signature format may not have bound the partition into what it signed.
 * When the signer returns claims WITH a partition, verification requires it to match
 * the chain; when it returns null, the partition is taken on the row's word. The
 * package's own signer always binds it.
 */
readonly class CheckpointClaims
{
    public function __construct(
        public string $scope,
        public int $upToSequence,
        public string $rootHash,
        public int $issuedAt,
        public ?string $partition = null,
    ) {}

    public static function forHead(ChainKey $key, int $upToSequence, string $rootHash, int $issuedAt): self
    {
        return new self($key->scope, $upToSequence, $rootHash, $issuedAt, $key->partition);
    }
}
