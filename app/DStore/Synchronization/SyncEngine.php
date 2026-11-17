<?php

namespace App\DStore\Synchronization;

use App\DStore\Monitoring\HeartbeatService;
use App\DStore\Network\PeerClient;
use App\DStore\Network\PeerDirectory;
use App\DStore\Replication\EventRelay;
use App\DStore\Replication\EventReplicator;
use App\DStore\Security\EventVerifier;
use App\DStore\Storage\Event;
use App\DStore\Storage\EventLog;

/**
 * Drives the bidirectional synchronisation with a peer.
 *
 * One pass does three things, in this order:
 *
 *  1. a heartbeat, so both sides exchange their applied vectors;
 *  2. a pull, so anything the peer has and we lack is fetched and applied;
 *  3. a push, so anything we have and the peer lacks is delivered.
 *
 * Because both directions read the applied vectors, a node that was offline
 * catches up on reconnect without any operator involvement.
 */
final class SyncEngine
{
    public function __construct(
        private readonly PeerClient $client,
        private readonly PeerDirectory $directory,
        private readonly EventLog $events,
        private readonly EventApplier $applier,
        private readonly EventReplicator $replicator,
        private readonly DeliveryTracker $tracker,
        private readonly EventVerifier $verifier,
        private readonly EventRelay $relay,
        private readonly HeartbeatService $heartbeat,
        private readonly ?SyncHistory $history = null,
    ) {}

    public function sync(string $peerId): SyncReport
    {
        $started = microtime(true);
        $type = app()->runningInConsole() ? 'auto' : 'manual';

        $this->directory->require($peerId);

        $beat = $this->heartbeat->beat($peerId);

        if (! $beat->reachable) {
            $error = $beat->error;

            $this->tracker->recordFailure($peerId, $error, $this->events->pendingCount($peerId));

            $report = new SyncReport($peerId, false, 0, 0, 0, 0, 0, 0, $this->events->pendingCount($peerId), $error);
            $this->logHistory($peerId, $type, $started, $report);

            return $report;
        }

        $peerVector = $beat->appliedVector;
        $localVector = $this->events->appliedVector();

        $this->tracker->recordHeartbeat($peerId, $peerVector, $this->stateFor($localVector, $peerVector));

        $pull = $this->pull($peerId);

        $push = $this->replicator->push($peerId);

        $report = new SyncReport(
            $peerId,
            true,
            $pull['pulled'],
            $pull['applied'],
            $pull['duplicates'],
            $pull['rejected'],
            $push->pushed,
            $push->delivered,
            $push->pending,
        );

        $this->logHistory($peerId, $type, $started, $report);

        return $report;
    }

    private function logHistory(string $peerId, string $type, float $started, SyncReport $report): void
    {
        $history = $this->history;

        if ($history === null && function_exists('app')) {
            try {
                $history = app(SyncHistory::class);
            } catch (\Throwable) {
                $history = null;
            }
        }

        if ($history === null) {
            return;
        }

        try {
            $durationMs = (microtime(true) - $started) * 1000;
            $history->log($peerId, $type, $durationMs, $report->toArray());
        } catch (\Throwable) {
            // History is observability only; never fail a sync because of it.
        }
    }

    /**
     * Fetches the events this node has not applied yet and applies them.
     *
     * @return array{pulled: int, applied: int, duplicates: int, rejected: int}
     */
    public function pull(string $peerId): array
    {
        $peer = $this->directory->require($peerId);

        $response = $this->client->get($peer, '/api/dstore/v1/events', [
            'after' => $this->vectorQuery($this->events->appliedVector()),
        ]);

        if (! $response->successful) {
            return ['pulled' => 0, 'applied' => 0, 'duplicates' => 0, 'rejected' => 0];
        }

        $events = (array) ($response->body('events') ?? []);

        $applied = 0;
        $duplicates = 0;
        $rejected = 0;

        foreach ($events as $raw) {
            if (! is_array($raw)) {
                $rejected++;

                continue;
            }

            $event = Event::fromArray($raw);

            // Verified before anything is written: a forged event must never
            // reach the committed log.
            if (! $this->verifier->isTrustedOrigin($event->origin)) {
                $rejected++;

                continue;
            }

            if (! $this->verifier->verify($event)) {
                $rejected++;

                continue;
            }

            // Durable local copy first, so this node can serve the event to
            // the rest of the mesh even if the peer goes offline later.
            $this->events->append($event);

            $known = $this->events->hasApplied($event->eventId);

            $outcome = $this->applier->apply($event);

            if (! $known) {
                $this->relay->relay($event, $peerId);
            }

            if ($outcome->status === ApplyOutcome::APPLIED || $outcome->status === ApplyOutcome::CONFLICT) {
                $applied++;
            } elseif ($outcome->status === ApplyOutcome::DUPLICATE || $outcome->status === ApplyOutcome::STALE) {
                $duplicates++;
            } else {
                $rejected++;
            }
        }

        return [
            'pulled' => count($events),
            'applied' => $applied,
            'duplicates' => $duplicates,
            'rejected' => $rejected,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function localVector(): array
    {
        return $this->events->appliedVector();
    }

    /**
     * The applied vector in the form the wire protocol expects.
     */
    private function vectorQuery(array $vector): string
    {
        $parts = [];

        foreach ($vector as $origin => $seq) {
            $parts[] = $origin.':'.(int) $seq;
        }

        return implode(',', $parts);
    }

    /**
     * @param  array<string, int>  $local
     * @param  array<string, int>  $peer
     */
    private function stateFor(array $local, array $peer): string
    {
        $localTotal = array_sum($local);
        $peerTotal = array_sum($peer);

        if ($localTotal === $peerTotal) {
            return SyncCursor::STATE_IN_SYNC;
        }

        return $localTotal < $peerTotal ? SyncCursor::STATE_BEHIND : SyncCursor::STATE_AHEAD;
    }
}
