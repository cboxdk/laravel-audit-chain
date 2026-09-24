<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Contracts;

use Cbox\AuditChain\Exceptions\CheckpointClaimsMalformed;
use Cbox\AuditChain\Exceptions\CheckpointSignatureInvalid;
use Cbox\AuditChain\Exceptions\CheckpointSigningUnavailable;
use Cbox\AuditChain\ValueObjects\CheckpointClaims;

/**
 * Signs checkpoint claims into a portable token, and verifies one back.
 *
 * The package default is Ed25519 over canonical JSON through ext-sodium. Bind your
 * own to sign with a key you already manage (a KMS, your JWT key set) — verification
 * MUST pin the algorithms it accepts rather than trust the token to name one.
 */
interface CheckpointSigner
{
    /**
     * @throws CheckpointSigningUnavailable when no signing key is available
     */
    public function sign(CheckpointClaims $claims): string;

    /**
     * @throws CheckpointSignatureInvalid when the token does not verify
     * @throws CheckpointClaimsMalformed when it verifies but is not a checkpoint claim set
     */
    public function verify(string $token): CheckpointClaims;
}
