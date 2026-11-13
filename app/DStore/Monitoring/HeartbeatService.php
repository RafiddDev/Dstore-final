<?php

namespace App\DStore\Monitoring;

use App\DStore\Network\PeerClient;
use App\DStore\Network\PeerDirectory;
use App\DStore\Security\IdentityRegistry;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\EventLog;
use App\DStore\Support\Timestamp;

/**
 * Sends one signed heartbeat to one peer and records the outcome.
 *
 * The heartbeat is the only place that is allowed to decide a peer is
 * reachable. Sync also calls it, so a node that is being synced cannot look
 * offline merely because nobody ran the monitor.
 *
 * A heartbeat is bidirectional information:
 *
 *  - the request carries this node's epoch, applied vector and health, so the
 *    receiver can update its own view without a second round-trip;
 *  - the reply carries the peer's epoch and health, which is what lets this
 *    node tell a restart (epoch changed) from a reconnect (epoch unchanged).
 */
final class HeartbeatService
{
    public function __construct(
        private readonly PeerClient $client,
        private readonly PeerDirectory $directory,
        private readonly PeerStatusStore $statuses,
        private readonly FailureDetector $detector,
        private readonly NodeRuntime $runtime,
        private readonly StorageEngineInterface $engine,
        private readonly EventLog $events,
        private readonly IdentityRegistry $identities,
    ) {}

    public function beat(string $peerId): HeartbeatResult
    {
        $peer = $this->directory->require($peerId);

        if ($peer->url === '') {
            $error = 'no URL configured for this peer';
            $this->statuses->recordFailure($peerId, $error);

            return $this->failure($peerId, $error, 1, 0.0);
        }

        $response = $this->client->post($peer, '/api/dstore/v1/heartbeat', $this->payload());

        if (! $response->successful) {
            $error = (string) ($response->error ?? 'heartbeat failed');
            $this->statuses->recordFailure($peerId, $error);

            return $this->failure($peerId, $error, $response->attempts, $response->durationMs);
        }

        $body = $response->body;
        $degraded = (bool) ($body['degraded'] ?? false);
        $state = $degraded ? PeerStatusStore::STATE_DEGRADED : PeerStatusStore::STATE_ONLINE;

        $recorded = $this->statuses->recordHeartbeat($peerId, $response->durationMs, $state, [
            'name' => $body['name'] ?? null,
            'region' => $body['region'] ?? null,
            'url' => $body['url'] ?? $peer->url,
            'epoch' => $body['epoch'] ?? null,
            'started_at' => $body['started_at'] ?? null,
            'reported_at' => $body['time'] ?? Timestamp::now(),
            'records' => $body['records'] ?? null,
            'events_committed' => $body['events_committed'] ?? null,
            'storage_bytes' => $body['storage_bytes'] ?? null,
            'degraded' => $degraded,
        ]);

        return new HeartbeatResult(
            $peerId,
            true,
            $recorded['status']['state'],
            (array) ($body['applied_vector'] ?? []),
            (string) ($body['epoch'] ?? ''),
            (bool) $recorded['restarted'],
            $response->durationMs,
            $response->attempts,
        );
    }

    /**
     * The heartbeat body this node sends. The receiver stores the parts it is
     * allowed to trust (it still re-derives the sender from the signed
     * envelope, never from this array).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $identity = $this->identities->identity();
        $stats = $this->engine->stats();

        return [
            'applied_vector' => $this->events->appliedVector(),
            'epoch' => $this->runtime->epoch(),
            'started_at' => $this->runtime->startedAt(),
            'uptime_seconds' => $this->runtime->uptimeSeconds(),
            'records' => $stats['total_records'],
            'events_committed' => $stats['events_committed'],
            'storage_bytes' => $stats['storage_bytes'],
            'degraded' => (bool) $stats['degraded'],
            'pending_total' => $stats['pending_total'],
            'name' => $identity->name,
            'region' => $identity->region,
            'url' => $identity->url,
            'reported_at' => Timestamp::now(),
        ];
    }

    private function failure(string $peerId, string $error, int $attempts, float $durationMs): HeartbeatResult
    {
        $evaluation = $this->detector->evaluate($peerId);

        return new HeartbeatResult(
            $peerId,
            false,
            $evaluation['state'],
            [],
            '',
            false,
            $durationMs,
            $attempts,
            $error,
        );
    }
}
