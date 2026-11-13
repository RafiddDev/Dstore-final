<?php

namespace App\DStore\Network;

use App\DStore\Security\BootstrapToken;
use App\DStore\Security\IdentityRegistry;
use App\DStore\Security\MessageSigner;
use App\DStore\Security\PeerRegistry;
use App\DStore\Security\RegistrationProof;

/**
 * Enrols this node with a peer.
 *
 * The request proves two things: that we know the shared bootstrap token and
 * that we hold the private key behind the public key we present. The reply
 * carries the peer's own identity, which is then pinned in our trust store.
 */
final class RegistrationService
{
    public function __construct(
        private readonly PeerClient $client,
        private readonly PeerDirectory $directory,
        private readonly IdentityRegistry $identities,
        private readonly PeerRegistry $registry,
        private readonly BootstrapToken $bootstrap,
        private readonly MessageSigner $signer,
    ) {}

    public function enroll(string $peerId): PeerResponse
    {
        $peer = $this->directory->require($peerId);
        $identity = $this->identities->identity();

        $body = [
            'node_id' => $identity->nodeId,
            'name' => $identity->name,
            'region' => $identity->region,
            'url' => $identity->url,
            'public_key' => $identity->publicKey,
            'nonce' => bin2hex(random_bytes(16)),
            'token' => $this->bootstrap->reveal(),
        ];

        $body['signature'] = $this->signer->signRaw(RegistrationProof::encode($body));

        $response = $this->client->postRaw($peer, '/api/dstore/v1/nodes/register', $body);

        if ($response->successful) {
            $this->pinPeer($peerId, $response);
        }

        return $response;
    }

    public function enrollAll(): array
    {
        $results = [];

        foreach ($this->directory->all() as $peerId => $peer) {
            $results[$peerId] = $this->enroll($peerId);
        }

        return $results;
    }

    private function pinPeer(string $peerId, PeerResponse $response): void
    {
        $node = $response->body('node');

        if (! is_array($node)) {
            return;
        }

        $publicKey = (string) ($node['public_key'] ?? '');

        if ($publicKey === '') {
            return;
        }

        $this->registry->approve(
            $peerId,
            $publicKey,
            (string) ($node['url'] ?? ''),
            (string) ($node['name'] ?? ''),
            (string) ($node['region'] ?? ''),
        );
    }
}
