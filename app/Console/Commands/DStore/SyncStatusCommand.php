<?php

namespace App\Console\Commands\DStore;

use App\DStore\Network\PeerDirectory;
use App\DStore\Storage\EventLog;
use App\DStore\Synchronization\DeliveryTracker;
use Illuminate\Console\Command;

class SyncStatusCommand extends Command
{
    protected $signature = 'dstore:sync:status';

    protected $description = 'Show the replication state of every peer';

    public function handle(EventLog $events, DeliveryTracker $tracker, PeerDirectory $directory): int
    {
        $local = $events->appliedVector();

        $this->line('Local applied vector: '.json_encode($local));
        $this->newLine();

        $peers = $directory->all();

        if ($peers === []) {
            $this->warn('No peers are configured for this node.');

            return self::SUCCESS;
        }

        foreach (array_keys($peers) as $peerId) {
            $cursor = $tracker->cursor((string) $peerId);
            $pending = $events->pendingCount((string) $peerId);

            $this->line(sprintf(
                '%-10s state=%-12s pending=%-4d attempts=%-3d failed=%s',
                $cursor->peer,
                $cursor->state,
                $pending,
                $cursor->attempts,
                $cursor->failed ? 'yes' : 'no',
            ));

            $this->line(sprintf(
                '           peer applied=%s  our delivered=%s',
                json_encode($cursor->applied),
                json_encode($cursor->delivered),
            ));

            if ($cursor->lastError !== null) {
                $this->warn('           last error: '.$cursor->lastError);
            }

            if ($cursor->lastSyncAt !== null) {
                $this->line('           last sync at: '.$cursor->lastSyncAt);
            }
        }

        return self::SUCCESS;
    }
}
