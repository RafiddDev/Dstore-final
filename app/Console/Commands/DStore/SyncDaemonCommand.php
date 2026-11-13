<?php

namespace App\Console\Commands\DStore;

use App\DStore\Monitoring\NodeRuntime;
use App\DStore\Network\PeerDirectory;
use App\DStore\Synchronization\SyncEngine;
use Illuminate\Console\Command;

/**
 * Keeps the node in sync with its peers for as long as it runs.
 *
 * This is the background worker that turns the local commit queue into
 * replicated data. It is safe to run on every node: the exchange is
 * idempotent, so two nodes syncing each other at the same time converges on
 * the same state.
 */
class SyncDaemonCommand extends Command
{
    protected $signature = 'dstore:sync:daemon
        {--interval=5 : Seconds between sync passes}
        {--once : Run a single pass and exit}';

    protected $description = 'Continuously synchronise with every peer';

    public function handle(SyncEngine $sync, PeerDirectory $directory, NodeRuntime $runtime): int
    {
        $interval = max(1, (int) $this->option('interval'));
        $once = (bool) $this->option('once');

        $runtime->beginRun();

        $peers = array_keys($directory->all());

        if ($peers === []) {
            $this->warn('No peers are configured for this node.');

            return self::SUCCESS;
        }

        do {
            foreach ($peers as $peerId) {
                $report = $sync->sync((string) $peerId);

                if (! $report->reachable) {
                    $this->warn(sprintf('%s unreachable: %s', $peerId, $report->error));

                    continue;
                }

                $this->line(sprintf(
                    '%s pulled=%d applied=%d pushed=%d delivered=%d pending=%d',
                    $peerId,
                    $report->pulled,
                    $report->applied,
                    $report->pushed,
                    $report->delivered,
                    $report->pending,
                ));
            }

            if ($once) {
                break;
            }

            sleep($interval);
        } while (true);

        return self::SUCCESS;
    }
}
