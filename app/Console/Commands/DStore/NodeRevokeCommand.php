<?php

namespace App\Console\Commands\DStore;

use App\DStore\Security\PeerRegistry;
use Illuminate\Console\Command;

class NodeRevokeCommand extends Command
{
    protected $signature = 'dstore:node:revoke {peer : The peer node id}';

    protected $description = 'Revoke a peer so it can no longer send signed messages';

    public function handle(PeerRegistry $peers): int
    {
        $peerId = (string) $this->argument('peer');

        if (! $peers->revoke($peerId)) {
            $this->error("Peer {$peerId} is not known to this node.");

            return self::FAILURE;
        }

        $this->info("Peer {$peerId} is revoked.");

        return self::SUCCESS;
    }
}
