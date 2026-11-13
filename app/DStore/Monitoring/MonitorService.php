<?php

namespace App\DStore\Monitoring;

use App\DStore\Network\PeerClient;
use App\DStore\Network\PeerDirectory;
use App\DStore\Security\IdentityRegistry;
use App\DStore\Storage\Contracts\StorageEngineInterface;

/**
 * One monitoring round: heartbeat every peer, let the failure detector
 * decide the state, then gossip what was observed.
 *
 * Gossip is informational only. A direct heartbeat is always authoritative,
 * so the receiver stores a gossip row only when it has never observed that
 * peer itself (see NodeController).
 */
final class MonitorService
{
    public function __construct(
        private readonly HeartbeatService $heartbeats,
        private readonly PeerDirectory $directory,
        private readonly FailureDetector $detector,
        private readonly PeerStatusStore $statuses,
        private readonly PeerClient $client,
        private readonly NodeRuntime $runtime,
        private readonly StorageEngineInterface $engine,
        private readonly IdentityRegistry $identities,
    ) {}

    /**
     * @return array{self: array, peers: array<string, array>, online: int, total: int, gossiped: int}
     */
    public function run(bool $gossip = true): array
    {
        $results = [];
        $reachable = [];

        foreach (array_keys($this->directory->all()) as $peerId) {
            $beat = $this->heartbeats->beat((string) $peerId);

            if ($beat->reachable) {
                $reachable[] = (string) $peerId;
            }

            $results[(string) $peerId] = $beat->toArray();
            $results[(string) $peerId]['detected'] = $this->detector->evaluate((string) $peerId);
        }

        $this->detector->refresh(array_keys($this->directory->all()));

        $gossiped = $gossip ? $this->gossip($reachable) : 0;

        $online = 0;

        foreach ($results as $result) {
            if (($result['detected']['state'] ?? '') === PeerStatusStore::STATE_ONLINE) {
                $online++;
            }
        }

        return [
            'self' => $this->selfStatus(),
            'peers' => $results,
            'online' => $online,
            'total' => count($results),
            'gossiped' => $gossiped,
        ];
    }

    /**
     * The dashboard's view of this node. It is computed, never stored, so it
     * cannot go stale.
     *
     * @return array<string, mixed>
     */
    public function selfStatus(): array
    {
        $identity = $this->identities->identity();
        $stats = $this->engine->stats();

        return [
            'node_id' => $identity->nodeId,
            'name' => $identity->name,
            'region' => $identity->region,
            'url' => $identity->url,
            'state' => $stats['degraded'] ? PeerStatusStore::STATE_DEGRADED : PeerStatusStore::STATE_ONLINE,
            'degraded' => (bool) $stats['degraded'],
            'epoch' => $this->runtime->epoch(),
            'started_at' => $this->runtime->startedAt(),
            'uptime_seconds' => $this->runtime->uptimeSeconds(),
            'records' => $stats['total_records'],
            'collections' => $stats['collections'],
            'events_committed' => $stats['events_committed'],
            'events_applied' => $stats['events_applied'],
            'pending_total' => $stats['pending_total'],
            'pending' => $stats['pending'],
            'conflicts' => $stats['conflicts'],
            'storage_bytes' => $stats['storage_bytes'],
            'issues' => $stats['issues'],
        ];
    }

    /**
     * @param  list<string>  $reachable
     * @return int number of peers the gossip was accepted by
     */
    public function gossip(array $reachable): int
    {
        $observations = [];

        foreach ($this->statuses->all() as $peerId => $status) {
            if ((string) $peerId === $this->identities->identity()->nodeId) {
                continue;
            }

            $observations[] = [
                'node_id' => (string) $peerId,
                'state' => (string) ($status['state'] ?? PeerStatusStore::STATE_UNKNOWN),
                'epoch' => (string) ($status['epoch'] ?? ''),
                'last_seen_at' => $status['last_seen_at'] ?? null,
            ];
        }

        if ($observations === [] || $reachable === []) {
            return 0;
        }

        $accepted = 0;

        foreach ($this->directory->all() as $peer) {
            if (! in_array($peer->id, $reachable, true) || $peer->url === '') {
                continue;
            }

            $response = $this->client->post($peer, '/api/dstore/v1/nodes/status', [
                'observations' => $observations,
            ]);

            if ($response->successful) {
                $accepted++;
            }
        }

        return $accepted;
    }
}
