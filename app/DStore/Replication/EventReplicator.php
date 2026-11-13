<?php

namespace App\DStore\Replication;

use App\DStore\Network\PeerClient;
use App\DStore\Network\PeerDirectory;
use App\DStore\Storage\Event;
use App\DStore\Storage\EventLog;
use App\DStore\Storage\EventQuarantine;
use App\DStore\Synchronization\DeliveryTracker;

/**
 * Pushes the events a peer has not seen yet.
 *
 * The rule that matters here: **an HTTP 200 does not mean the data is
 * synchronised**. An event counts as delivered to a peer only when that peer
 * reports it in its own applied vector. Anything else stays in the pending
 * queue and is retried.
 */
final class EventReplicator
{
    /**
     * Refusals that mean the event itself is broken. Such an event can
     * never be delivered, so retrying it would block every event behind it.
     */
    private const PERMANENT_REFUSALS = [
        'malformed event',
        'event has no id',
        'invalid event signature',
    ];

    public function __construct(
        private readonly PeerClient $client,
        private readonly PeerDirectory $directory,
        private readonly EventLog $events,
        private readonly DeliveryTracker $tracker,
        private readonly EventQuarantine $quarantine,
        private readonly string $nodeId,
        private readonly int $batchSize = 100,
        private readonly int $maxAttempts = 10,
    ) {}

    public function push(string $peerId): ReplicationResult
    {
        $peer = $this->directory->require($peerId);
        $pending = $this->events->pendingEvents($peerId);

        if ($pending === []) {
            return ReplicationResult::nothingToPush($peerId, 0);
        }

        $batch = array_slice($pending, 0, $this->batchSize);
        $payload = ['events' => array_map(fn (Event $event): array => $event->toArray(), $batch)];

        $response = $this->client->post($peer, '/api/dstore/v1/events/push', $payload);

        $remaining = $this->events->pendingCount($peerId);

        if (! $response->successful) {
            $error = (string) $response->error;

            if ($this->tracker->cursor($peerId)->attempts + 1 >= $this->maxAttempts) {
                $this->tracker->markFailed($peerId, $error, $remaining);
            } else {
                $this->tracker->recordFailure($peerId, $error, $remaining);
            }

            return ReplicationResult::failed($peerId, count($batch), $remaining, $error);
        }

        $appliedVector = (array) ($response->body('applied_vector') ?? []);
        $delivered = 0;

        foreach ($batch as $event) {
            if ((int) ($appliedVector[$event->origin] ?? 0) >= $event->seq) {
                $this->events->markDelivered($peerId, $event->origin, $event->seq);
                $delivered++;
            }
        }

        $this->quarantineRefused($peerId, $batch, (array) ($response->body('rejected') ?? []));

        $this->tracker->recordDelivery(
            $peerId,
            $appliedVector,
            [$this->nodeId => (int) ($appliedVector[$this->nodeId] ?? 0)],
            $this->events->pendingCount($peerId),
        );

        return ReplicationResult::pushed(
            $peerId,
            count($batch),
            $delivered,
            $this->events->pendingCount($peerId),
            $appliedVector,
        );
    }

    /**
     * Sets aside the events a peer refused for good.
     *
     * A peer that refuses an event because it does not trust this node yet
     * is a different story: the event is fine and must stay queued. Only a
     * refusal that points at the event itself is permanent.
     *
     * @param  Event[]  $batch
     */
    private function quarantineRefused(string $peerId, array $batch, array $rejected): void
    {
        $refusals = [];

        foreach ($rejected as $item) {
            if (! is_array($item)) {
                continue;
            }

            $eventId = (string) ($item['event_id'] ?? '');
            $reason = (string) ($item['reason'] ?? '');

            if ($eventId === '' || ! in_array($reason, self::PERMANENT_REFUSALS, true)) {
                continue;
            }

            $refusals[$eventId] = $reason;
        }

        if ($refusals === []) {
            return;
        }

        foreach ($batch as $event) {
            $reason = $refusals[$event->eventId] ?? null;

            if ($reason === null) {
                continue;
            }

            $this->quarantine->quarantine($event, $reason, $peerId);
            $this->events->dropPending($peerId, $event->origin, $event->seq);
        }
    }
}
