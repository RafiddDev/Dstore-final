<?php

namespace Tests\Feature\Security;

use App\DStore\Security\Envelope;
use App\DStore\Security\Exceptions\ReplayDetectedException;
use App\DStore\Security\Exceptions\SignatureVerificationException;
use App\DStore\Security\Exceptions\UnauthorizedNodeException;
use App\DStore\Security\KeyManager;
use App\DStore\Security\MessageAuthenticator;
use App\DStore\Security\MessageSigner;
use App\DStore\Security\PeerRegistry;
use App\DStore\Storage\StoragePaths;
use Tests\TestCase;

class MessageAuthenticatorTest extends TestCase
{
    private function peerKeyManager(): KeyManager
    {
        $manager = new KeyManager(
            new StoragePaths($this->storagePath('peer-uae')),
            (string) config('app.key'),
        );

        $manager->publicKey();

        return $manager;
    }

    private function approvePeer(KeyManager $peer): void
    {
        $this->app->make(PeerRegistry::class)->approve('uae', $peer->publicKey(), 'http://127.0.0.1:8002');
    }

    private function envelopeFrom(KeyManager $peer, array $payload = ['ping' => 1], string $messageId = 'msg-1'): Envelope
    {
        $envelope = new Envelope($messageId, 'uae', time(), bin2hex(random_bytes(8)), $payload);

        $signature = (new MessageSigner($peer))->sign($envelope->signableContent());

        return $envelope->withSignature($signature, 'peer-fingerprint');
    }

    public function test_a_signed_message_from_an_approved_peer_is_authenticated(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $verified = $this->app->make(MessageAuthenticator::class)->authenticate(
            $this->envelopeFrom($peer)->toArray()
        );

        $this->assertSame('uae', $verified->originNode);
        $this->assertSame(['ping' => 1], $verified->payload);
    }

    public function test_a_message_from_an_unknown_node_is_refused(): void
    {
        $peer = $this->peerKeyManager();

        $this->expectException(UnauthorizedNodeException::class);

        $this->app->make(MessageAuthenticator::class)->authenticate(
            $this->envelopeFrom($peer)->toArray()
        );
    }

    public function test_a_revoked_node_is_refused(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $this->app->make(PeerRegistry::class)->revoke('uae');

        $this->expectException(UnauthorizedNodeException::class);

        $this->app->make(MessageAuthenticator::class)->authenticate(
            $this->envelopeFrom($peer)->toArray()
        );
    }

    public function test_a_tampered_message_is_refused(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $envelope = $this->envelopeFrom($peer)->toArray();
        $envelope['payload']['ping'] = 999;

        $this->expectException(SignatureVerificationException::class);

        $this->app->make(MessageAuthenticator::class)->authenticate($envelope);
    }

    public function test_an_unsigned_message_is_refused(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $envelope = $this->envelopeFrom($peer)->toArray();
        $envelope['signature'] = null;

        $this->expectException(SignatureVerificationException::class);

        $this->app->make(MessageAuthenticator::class)->authenticate($envelope);
    }

    public function test_a_replayed_message_is_refused(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $envelope = $this->envelopeFrom($peer)->toArray();

        $this->app->make(MessageAuthenticator::class)->authenticate($envelope);

        $this->expectException(ReplayDetectedException::class);

        $this->app->make(MessageAuthenticator::class)->authenticate($envelope);
    }

    public function test_an_outbound_envelope_is_signed_by_this_node(): void
    {
        $envelope = $this->app->make(MessageAuthenticator::class)->sign(['ping' => 1]);

        $this->assertSame('test-node', $envelope->originNode);
        $this->assertNotNull($envelope->signature);
        $this->assertNotSame('', $envelope->signature);
    }
}
