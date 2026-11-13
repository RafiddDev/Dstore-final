<?php

namespace App\Console\Commands\DStore;

use App\DStore\Security\PeerRegistry;
use Illuminate\Console\Command;

class NodeApproveCommand extends Command
{
    protected $signature = 'dstore:node:approve {peer : The peer node id} {--public-key= : The peer\'s base64 Ed25519 public key}';

    protected $description = 'Approve a peer and pin its Ed25519 public key';

    public function handle(PeerRegistry $peers): int
    {
        $peerId = (string) $this->argument('peer');
        $publicKey = (string) ($this->option('public-key') ?? '');

        if ($publicKey === '') {
            $this->error('The --public-key option is required. Read it from the peer with "dstore:node:list" on that node.');

            return self::FAILURE;
        }

        $existing = $peers->get($peerId);

        try {
            $peer = $peers->approve(
                $peerId,
                $publicKey,
                (string) ($existing['url'] ?? ''),
                (string) ($existing['name'] ?? ''),
                (string) ($existing['region'] ?? ''),
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Peer {$peerId} is now approved.");
        $this->line('Fingerprint: '.$peer['fingerprint']);

        return self::SUCCESS;
    }
}
