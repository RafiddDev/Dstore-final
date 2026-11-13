<?php

namespace App\Console\Commands\DStore;

use App\DStore\Security\IdentityRegistry;
use App\DStore\Security\PeerRegistry;
use Illuminate\Console\Command;

class NodeListCommand extends Command
{
    protected $signature = 'dstore:node:list';

    protected $description = 'Show this node\'s identity and the status of every known peer';

    public function handle(IdentityRegistry $identities, PeerRegistry $peers): int
    {
        if (! $identities->exists()) {
            $this->warn('This node has no identity yet. Run "php artisan dstore:node:keygen".');

            return self::FAILURE;
        }

        $identity = $identities->identity();

        $this->info('This node');
        $this->line('  Id:           '.$identity->nodeId);
        $this->line('  Name:         '.$identity->name);
        $this->line('  Region:       '.$identity->region);
        $this->line('  URL:          '.$identity->url);
        $this->line('  Fingerprint:  '.$identity->shortFingerprint());

        $this->newLine();
        $this->info('Peers');

        $registry = $peers->all();

        if ($registry === []) {
            $this->line('  (none configured)');

            return self::SUCCESS;
        }

        foreach ($registry as $peer) {
            $this->line(sprintf(
                '  %-10s %-10s %s',
                $peer['id'],
                $peer['status'],
                $peer['fingerprint'] !== null ? substr((string) $peer['fingerprint'], 0, 16) : '(no key pinned)',
            ));

            if ($peer['url'] !== '') {
                $this->line('             url: '.$peer['url']);
            }
        }

        return self::SUCCESS;
    }
}
