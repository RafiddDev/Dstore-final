<?php

namespace App\Console\Commands\DStore;

use App\DStore\Security\IdentityRegistry;
use Illuminate\Console\Command;

class NodeKeygenCommand extends Command
{
    protected $signature = 'dstore:node:keygen {--rotate : Replace an existing key pair}';

    protected $description = 'Create this node\'s identity card and Ed25519 key pair';

    public function handle(IdentityRegistry $identities): int
    {
        $existed = $identities->exists();

        if ($existed && ! $this->option('rotate')) {
            $this->warn('This node already has an identity. Use --rotate to replace it.');

            $identity = $identities->identity();

            $this->line('Node id:      '.$identity->nodeId);
            $this->line('Fingerprint:  '.$identity->shortFingerprint());

            return self::SUCCESS;
        }

        $identity = $existed ? $identities->rotate() : $identities->create();

        $this->info($existed ? 'Node key pair rotated.' : 'Node identity created.');
        $this->line('Node id:      '.$identity->nodeId);
        $this->line('Name:         '.$identity->name);
        $this->line('Region:       '.$identity->region);
        $this->line('URL:          '.$identity->url);
        $this->line('Public key:   '.$identity->publicKey);
        $this->line('Fingerprint:  '.$identity->shortFingerprint());

        if ($existed) {
            $this->warn('The old public key is no longer valid. Every peer must enrol this node again.');
        }

        return self::SUCCESS;
    }
}
