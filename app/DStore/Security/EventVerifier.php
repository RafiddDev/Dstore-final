<?php

namespace App\DStore\Security;

use App\DStore\Storage\Event;

/**
 * Verifies the signature of an event against the pinned key of the node that
 * claims to have produced it.
 *
 * This node's own events are trusted too: recovery has to be able to replay
 * this node's committed log after the applied index or the record store was
 * lost, and the log already passed verification when it was written.
 */
final class EventVerifier
{
    public function __construct(
        private readonly PeerRegistry $peers,
        private readonly MessageSigner $signer,
        private readonly ?IdentityRegistry $identities = null,
        private readonly string $nodeId = '',
    ) {}

    public function verify(Event $event): bool
    {
        if ($event->signature === null || $event->signature === '') {
            return false;
        }

        $publicKey = $this->publicKeyFor($event->origin);

        if ($publicKey === null) {
            return false;
        }

        return $this->signer->verify($event->signableContent(), $event->signature, $publicKey);
    }

    public function isTrustedOrigin(string $origin): bool
    {
        if ($this->nodeId !== '' && $origin === $this->nodeId) {
            return true;
        }

        return $this->peers->isTrusted($origin);
    }

    private function publicKeyFor(string $origin): ?string
    {
        if ($this->nodeId !== '' && $origin === $this->nodeId) {
            return $this->identities?->identity()->publicKey;
        }

        return $this->peers->publicKeyFor($origin);
    }
}
