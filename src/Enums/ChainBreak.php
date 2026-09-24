<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Enums;

use Cbox\AuditChain\ValueObjects\ChainVerification;

/**
 * Why a chain failed verification.
 *
 * The string values are stable and part of the public contract: they are what
 * {@see ChainVerification::$reason} carries, and hosts
 * surface them in consoles and alerts.
 */
enum ChainBreak: string
{
    /** An entry is missing from the middle of the range, or entries are out of order. */
    case SequenceGap = 'sequence gap or reordering';

    /** An entry's `prev_hash` does not name the entry before it. */
    case LinkageMismatch = 'prev-hash linkage mismatch';

    /** An entry's stored content no longer produces its stored hash. */
    case ContentMismatch = 'content hash mismatch (tampered)';

    /** The newest checkpoint's signature does not verify against any trusted key. */
    case CheckpointSignatureInvalid = 'checkpoint signature failed to verify';

    /** The newest checkpoint's stored fields disagree with what was signed. */
    case CheckpointPayloadMismatch = 'checkpoint payload does not match its signature';

    /** The entry the newest checkpoint attests is gone or changed: truncation. */
    case CheckpointAnchorMissing = 'entries at or below the last checkpoint were removed or altered';
}
