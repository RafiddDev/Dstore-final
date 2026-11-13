<?php

namespace App\DStore\Security;

use App\DStore\Security\Exceptions\UnauthorizedNodeException;

/**
 * Enrols a peer: proves it holds the bootstrap token and the private key
 * behind the public key it presents, then pins that key so the peer can sign
 * messages from that point on.
 */
final class NodeRegistrar
{
    public function __construct(
        private readonly BootstrapToken $bootstrap,
        private readonly PeerRegistry $peers,
        private readonly IdentityRegistry $identities,
        private readonly MessageSigner $signer,
    ) {}

    /**
     * @return array the stored peer record
     */
    public function register(
        string $peerId,
        string $publicKey,
        ?string $bootstrapToken,
        string $url = '',
        string $name = '',
        string $region = '',
        ?string $nonce = null,
        ?string $signature = null,
    ): array {
        if ($peerId === '') {
            throw new UnauthorizedNodeException('A node id is required to register.');
        }

        if ($peerId === $this->identities->identity()->nodeId) {
            throw new UnauthorizedNodeException('A node cannot register itself as a peer.');
        }

        if (! $this->bootstrap->verify($bootstrapToken)) {
            throw new UnauthorizedNodeException("The bootstrap token presented by {$peerId} is not valid.");
        }

        if ($nonce === null || $nonce === '' || $signature === null || $signature === '') {
            throw new UnauthorizedNodeException(
                "Node {$peerId} did not prove possession of the presented key."
            );
        }

        $content = RegistrationProof::encode([
            'node_id' => $peerId,
            'public_key' => $publicKey,
            'url' => $url,
            'nonce' => $nonce,
        ]);

        if (! $this->signer->verifyRaw($content, $signature, $publicKey)) {
            throw new UnauthorizedNodeException(
                "Node {$peerId} did not prove possession of the presented key."
            );
        }

        return $this->peers->approve($peerId, $publicKey, $url, $name, $region);
    }
}
