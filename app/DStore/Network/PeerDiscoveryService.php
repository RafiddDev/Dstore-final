<?php

namespace App\DStore\Network;

use App\DStore\Monitoring\PeerStatusStore;

/**
 * Probes the peers and records what it found.
 */
final class PeerDiscoveryService
{
    public function __construct(
        private readonly PeerDirectory $directory,
        private readonly PeerClient $client,
        private readonly PeerStatusStore $statuses,
    ) {}

    /**
     * @return array<string, array> one summary row per peer
     */
    public function probe(?string $peerId = null): array
    {
        $peers = $peerId !== null
            ? array_filter($this->directory->all(), fn (PeerEndpoint $peer): bool => $peer->id === $peerId)
            : $this->directory->all();

        $summary = [];

        foreach ($peers as $peer) {
            $summary[$peer->id] = $this->probeOne($peer);
        }

        return $summary;
    }

    private function probeOne(PeerEndpoint $peer): array
    {
        if ($peer->url === '') {
            $this->statuses->recordFailure($peer->id, 'no URL configured for this peer');

            return [
                'peer' => $peer->id,
                'state' => PeerStatusStore::STATE_UNREACHABLE,
                'reachable' => false,
                'error' => 'no URL configured for this peer',
            ];
        }

        $response = $this->client->get($peer, '/api/dstore/v1/health');

        if ($response->successful) {
            $this->statuses->recordSuccess($peer->id, $response->durationMs);

            return [
                'peer' => $peer->id,
                'state' => PeerStatusStore::STATE_ONLINE,
                'reachable' => true,
                'node_id' => $response->body('node_id'),
                'response_ms' => $response->durationMs,
                'attempts' => $response->attempts,
                'error' => null,
            ];
        }

        $this->statuses->recordFailure($peer->id, (string) $response->error);

        return [
            'peer' => $peer->id,
            'state' => PeerStatusStore::STATE_UNREACHABLE,
            'reachable' => false,
            'status' => $response->status,
            'attempts' => $response->attempts,
            'error' => $response->error,
        ];
    }
}
