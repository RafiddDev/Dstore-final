<?php

namespace App\DStore\Security;

use App\DStore\Security\Exceptions\ReplayDetectedException;
use App\DStore\Security\Exceptions\SignatureVerificationException;
use App\DStore\Security\Exceptions\UnauthorizedNodeException;
use App\DStore\Support\Uuid;

/**
 * Signs outbound messages and authenticates inbound ones.
 */
final class MessageAuthenticator
{
    public function __construct(
        private readonly MessageSigner $signer,
        private readonly PeerRegistry $peers,
        private readonly ReplayGuard $replay,
        private readonly IdentityRegistry $identities,
    ) {}

    /**
     * Builds and signs an outbound envelope for this node.
     */
    public function sign(array $payload, ?string $messageId = null): Envelope
    {
        $identity = $this->identities->identity();

        $envelope = new Envelope(
            $messageId ?? Uuid::v7(),
            $identity->nodeId,
            time(),
            bin2hex(random_bytes(16)),
            $payload,
        );

        $signature = $this->signer->sign($envelope->signableContent());

        return $envelope->withSignature($signature, $identity->shortFingerprint());
    }

    /**
     * Verifies that an inbound envelope really comes from a trusted peer and
     * has not been replayed.
     */
    public function authenticate(array $envelopeData): Envelope
    {
        $envelope = Envelope::fromArray($envelopeData);

        if ($envelope->messageId === '' || $envelope->originNode === '') {
            throw new SignatureVerificationException('Envelope is missing a message id or origin node.');
        }

        if ($envelope->signature === null || $envelope->signature === '') {
            throw new SignatureVerificationException('Envelope is not signed.');
        }

        $publicKey = $this->peers->publicKeyFor($envelope->originNode);

        if ($publicKey === null) {
            throw new UnauthorizedNodeException(
                "Node {$envelope->originNode} is not an approved peer of this node."
            );
        }

        if (! $this->signer->verify($envelope->signableContent(), $envelope->signature, $publicKey)) {
            throw new SignatureVerificationException(
                "Signature of message {$envelope->messageId} from {$envelope->originNode} is invalid."
            );
        }

        if (! $this->replay->accept($envelope->messageId, $envelope->timestamp)) {
            throw new ReplayDetectedException(
                "Message {$envelope->messageId} was rejected as stale or replayed."
            );
        }

        return $envelope;
    }
}
