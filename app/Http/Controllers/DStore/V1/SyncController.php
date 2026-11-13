<?php

namespace App\Http\Controllers\DStore\V1;

use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\EventInbox;
use App\DStore\Storage\EventLog;
use App\DStore\Synchronization\DeliveryTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Reports the replication state of this node: what it has applied, what it
 * still owes every peer, and what it is waiting to receive.
 */
class SyncController extends Controller
{
    public function __invoke(
        StorageEngineInterface $engine,
        EventLog $events,
        EventInbox $inbox,
        DeliveryTracker $tracker,
    ): JsonResponse {
        $stats = $engine->stats();

        $peers = [];

        foreach ($stats['pending'] as $peerId => $pendingCount) {
            $cursor = $tracker->cursor($peerId);

            $peers[$peerId] = [
                'peer' => $peerId,
                'pending' => $pendingCount,
                'last_sync_at' => $cursor->lastSyncAt,
                'last_heartbeat_at' => $cursor->lastHeartbeatAt,
                'state' => $cursor->state,
                'applied' => $cursor->applied,
                'delivered' => $cursor->delivered,
                'attempts' => $cursor->attempts,
                'last_error' => $cursor->lastError,
                'failed' => $cursor->failed,
            ];
        }

        return new JsonResponse([
            'ok' => true,
            'node_id' => $stats['node_id'],
            'applied_vector' => $events->appliedVector(),
            'events_committed' => $stats['events_committed'],
            'events_applied' => $stats['events_applied'],
            'inbox_pending' => $inbox->count(),
            'pending_total' => $stats['pending_total'],
            'peers' => $peers,
            'degraded' => $stats['degraded'],
        ]);
    }
}
