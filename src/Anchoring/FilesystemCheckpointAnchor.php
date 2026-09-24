<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Anchoring;

use Cbox\AuditChain\Codec\CanonicalJson;
use Cbox\AuditChain\Contracts\CheckpointAnchor;
use Cbox\AuditChain\Exceptions\CannotAnchorCheckpoint;
use Cbox\AuditChain\ValueObjects\SignedCheckpoint;
use Illuminate\Contracts\Filesystem\Filesystem;
use Throwable;

/**
 * Writes each signed checkpoint as a small JSON document to a Laravel filesystem disk
 * — local, S3, Cloudflare R2, anything with a Flysystem adapter.
 *
 * One document per checkpoint, at
 *
 *     <prefix>/<partition>/<scope>/<up_to_sequence, 20 digits>-<16 hex of SHA-256(token)>.json
 *
 * with each path segment percent-encoded (dots too, so no key can climb out of its
 * directory on a local disk). The document is the {@see CanonicalJson} encoding of
 * `{checkpoint_id, format, issued_at, partition, root_hash, scope, token, up_to_sequence}`
 * followed by a newline — the token alone is enough to re-verify it; the rest is there
 * so a human can read the bucket.
 *
 * Append-only from this side: an existing document with the same content is left
 * alone (so a retried export is harmless), and one with DIFFERENT content is never
 * overwritten — that is refused. What this class cannot do is stop someone else
 * overwriting or deleting objects: the append-only guarantee has to come from the
 * store itself (S3 Object Lock in compliance mode, an R2 bucket lock rule, a WORM
 * volume), with credentials the application's database writers do not hold.
 */
class FilesystemCheckpointAnchor implements CheckpointAnchor
{
    public const FORMAT = 'audit-chain.anchor/v1';

    public function __construct(
        private readonly Filesystem $disk,
        private readonly string $prefix = 'audit-chain/checkpoints',
    ) {}

    public function anchor(SignedCheckpoint $checkpoint): void
    {
        $path = $this->pathFor($checkpoint);
        $document = $this->documentFor($checkpoint);

        try {
            if ($this->disk->exists($path)) {
                if ($this->disk->get($path) === $document) {
                    return;
                }

                throw CannotAnchorCheckpoint::conflictingCopy($path);
            }

            if ($this->disk->put($path, $document) !== true) {
                throw CannotAnchorCheckpoint::because("the disk refused to write [{$path}]");
            }
        } catch (CannotAnchorCheckpoint $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            throw CannotAnchorCheckpoint::because($failure->getMessage(), $failure);
        }
    }

    public function pathFor(SignedCheckpoint $checkpoint): string
    {
        $segments = array_filter([trim($this->prefix, '/')], static fn (string $segment): bool => $segment !== '');

        $segments[] = self::segment($checkpoint->key->partition);
        $segments[] = self::segment($checkpoint->key->scope);
        $segments[] = sprintf('%020d-%s.json', $checkpoint->claims->upToSequence, substr(hash('sha256', $checkpoint->token), 0, 16));

        return implode('/', $segments);
    }

    public function documentFor(SignedCheckpoint $checkpoint): string
    {
        return CanonicalJson::encode([
            'checkpoint_id' => $checkpoint->checkpointId,
            'format' => self::FORMAT,
            'issued_at' => $checkpoint->claims->issuedAt,
            'partition' => $checkpoint->key->partition,
            'root_hash' => $checkpoint->claims->rootHash,
            'scope' => $checkpoint->key->scope,
            'token' => $checkpoint->token,
            'up_to_sequence' => $checkpoint->claims->upToSequence,
        ])."\n";
    }

    private static function segment(string $value): string
    {
        return str_replace('.', '%2E', rawurlencode($value));
    }
}
