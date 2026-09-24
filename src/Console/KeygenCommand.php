<?php

declare(strict_types=1);

namespace Cbox\AuditChain\Console;

use Cbox\AuditChain\Signing\Ed25519CheckpointSigner;
use Illuminate\Console\Command;

/**
 * `audit-chain:keygen` — print a fresh Ed25519 checkpoint signing key.
 *
 * Prints, never writes: where a secret key lives (an env file, a secret manager) is
 * the operator's decision, and a command that edits `.env` would also be the easiest
 * way to overwrite the key every existing checkpoint was signed with.
 */
class KeygenCommand extends Command
{
    protected $signature = 'audit-chain:keygen
        {--kid= : The key id to print (default: a dated random id)}';

    protected $description = 'Generate an Ed25519 key pair for signing audit checkpoints.';

    public function handle(): int
    {
        $kid = $this->option('kid');

        if ($kid === null || $kid === '') {
            $kid = 'acp-'.now()->format('Ymd').'-'.bin2hex(random_bytes(4));
        }

        if (! is_string($kid) || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $kid) !== 1) {
            $this->error('--kid must be 1-64 characters of [A-Za-z0-9_-].');

            return self::INVALID;
        }

        $pair = Ed25519CheckpointSigner::generateKeyPair();

        $this->line('AUDIT_CHAIN_SIGNING_KEY_ID='.$kid);
        $this->line('AUDIT_CHAIN_SIGNING_SECRET_KEY='.$pair['secret_key']);
        $this->newLine();
        $this->line('Public key (keep it in audit-chain.signing.public_keys under "'.$kid.'" when you rotate away from this key):');
        $this->line($pair['public_key']);
        $this->newLine();
        $this->comment('Store the secret key like any other credential. Never delete a retired public key: every checkpoint it signed stops verifying.');

        return self::SUCCESS;
    }
}
