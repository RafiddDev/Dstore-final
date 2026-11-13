<?php

namespace App\DStore\Synchronization;

use App\DStore\Storage\EventLog;
use App\DStore\Support\Timestamp;

/**
 * Keeps the per-peer checkpoint file up to date.
 *
 * The file lives at `events/sync/{peer}.json` and survives a restart, so a
 * node that was offline for a week still knows how far it had got with each
 * peer.
 */
final class DeliveryTracker
{
    public function __construct(private readonly EventLog $events) {}

    public function cursor(string $peerId): SyncCursor
    {
        $raw = $this->events->cursor($peerId);

        if ($raw === []) {
            return SyncCursor::empty($peerId);
        }

        return SyncCursor::fromArray($raw);
    }

    public function all(): array
    {
        $cursors = [];

        foreach ($this->events->pendingPeers() as $peerId) {
            $cursors[$peerId] = $this->cursor($peerId);
        }

        return $cursors;
    }

    /**
     * The peer answered: remember what it says it has applied.
     */
    public function recordHeartbeat(string $peerId, array $peerVector, string $state): void
    {
        $cursor = $this->cursor($peerId);

        $this->save(new SyncCursor(
            $peerId,
            $peerVector,
            $cursor->delivered,
            $cursor->pendingCount,
            Timestamp::now(),
            $cursor->lastSyncAt,
            $state,
            $cursor->attempts,
            null,
            false,
        ));
    }

    /**
     * A push was confirmed by the peer. The confirmation is the peer's own
     * applied vector, never the HTTP status.
     */
    public function recordDelivery(string $peerId, array $peerVector, array $delivered, int $pendingCount): void
    {
        $cursor = $this->cursor($peerId);

        $this->save(new SyncCursor(
            $peerId,
            $peerVector,
            $delivered,
            $pendingCount,
            $cursor->lastHeartbeatAt,
            Timestamp::now(),
            $cursor->state === SyncCursor::STATE_UNREACHABLE ? SyncCursor::STATE_IN_SYNC : $cursor->state,
            $cursor->attempts,
            null,
            false,
        ));
    }

    public function recordFailure(string $peerId, string $error, int $pendingCount): void
    {
        $cursor = $this->cursor($peerId);

        $this->save(new SyncCursor(
            $peerId,
            $cursor->applied,
            $cursor->delivered,
            $pendingCount,
            $cursor->lastHeartbeatAt,
            $cursor->lastSyncAt,
            SyncCursor::STATE_UNREACHABLE,
            $cursor->attempts + 1,
            $error,
            $cursor->failed,
        ));
    }

    public function markFailed(string $peerId, string $error, int $pendingCount): void
    {
        $cursor = $this->cursor($peerId);

        $this->save(new SyncCursor(
            $peerId,
            $cursor->applied,
            $cursor->delivered,
            $pendingCount,
            $cursor->lastHeartbeatAt,
            $cursor->lastSyncAt,
            SyncCursor::STATE_FAILED,
            $cursor->attempts,
            $error,
            true,
        ));
    }

    private function save(SyncCursor $cursor): void
    {
        $this->events->saveCursor($cursor->peer, $cursor->toArray());
    }
}
