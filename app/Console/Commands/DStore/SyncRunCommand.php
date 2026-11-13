<?php

namespace App\Console\Commands\DStore;

use App\DStore\Network\PeerDirectory;
use App\DStore\Synchronization\SyncEngine;
use Illuminate\Console\Command;

class SyncRunCommand extends Command
{
    protected $signature = 'dstore:sync:run {peer? : Sync with only this peer}';

    protected $description = 'Synchronise with the peers: heartbeat, pull what is missing, push what is owed';

    public function handle(SyncEngine $sync, PeerDirectory $directory): int
    {
        $peerId = $this->argument('peer');

        $peers = $peerId !== null
            ? array_filter($directory->all(), fn ($peer): bool => $peer->id === $peerId)
            : $directory->all();

        if ($peers === []) {
            $this->warn('No peers are configured for this node.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach (array_keys($peers) as $id) {
            $report = $sync->sync((string) $id);

            if (! $report->reachable) {
                $failed++;
                $this->warn(sprintf(
                    '%-10s %-12s %s',
                    $report->peer,
                    'unreachable',
                    $report->error,
                ));

                continue;
            }

            $this->info(sprintf(
                '%-10s pulled=%-4d applied=%-4d pushed=%-4d delivered=%-4d pending=%d',
                $report->peer,
                $report->pulled,
                $report->applied,
                $report->pushed,
                $report->delivered,
                $report->pending,
            ));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
