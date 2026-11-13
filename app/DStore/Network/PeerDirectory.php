<?php

namespace App\DStore\Network;

use App\DStore\Security\PeerRegistry;

/**
 * The set of peers this node knows about, merged from the environment
 * configuration and the trust store.
 */
final class PeerDirectory
{
    public function __construct(
        private readonly PeerRegistry $registry,
        private readonly array $configuredPeers = [],
    ) {}

    public function all(): array
    {
        $endpoints = [];

        foreach ($this->registry->all() as $id => $peer) {
            $endpoints[$id] = new PeerEndpoint(
                $id,
                (string) ($peer['url'] ?? ''),
                $peer['status'] === PeerRegistry::STATUS_APPROVED,
                $peer['fingerprint'] !== null ? (string) $peer['fingerprint'] : null,
            );
        }

        foreach ($this->configuredPeers as $id => $peer) {
            if (isset($endpoints[$id])) {
                continue;
            }

            $endpoints[$id] = new PeerEndpoint(
                $id,
                (string) ($peer['url'] ?? ''),
                false,
            );
        }

        ksort($endpoints);

        return $endpoints;
    }

    public function trusted(): array
    {
        return array_filter($this->all(), fn (PeerEndpoint $peer): bool => $peer->trusted);
    }

    public function find(string $peerId): ?PeerEndpoint
    {
        return $this->all()[$peerId] ?? null;
    }

    public function require(string $peerId): PeerEndpoint
    {
        $peer = $this->find($peerId);

        if ($peer === null) {
            throw new \App\DStore\Network\Exceptions\NetworkException(
                "Peer {$peerId} is not known to this node."
            );
        }

        return $peer;
    }
}
