<?php

namespace Tests\Support;

use App\DStore\Security\KeyManager;
use App\DStore\Security\MessageSigner;
use App\DStore\Security\PeerRegistry;
use App\DStore\Storage\Event;
use App\DStore\Storage\RecordDocument;
use App\DStore\Storage\StoragePaths;

/**
 * Builds events that look like they came from another node.
 *
 * The peer keeps its own key pair in its own directory, so the signature is
 * produced by the peer's key and verified against the key pinned in the
 * trust store — exactly like the real wire protocol.
 */
trait BuildsPeerEvents
{
    protected function peerKeyManager(string $name = 'peer-uae'): KeyManager
    {
        $manager = new KeyManager(
            new StoragePaths($this->storagePath($name)),
            (string) config('app.key'),
        );

        $manager->publicKey();

        return $manager;
    }

    protected function approvePeer(KeyManager $peer, string $peerId = 'uae', string $url = 'http://127.0.0.1:8002'): void
    {
        $this->app->make(PeerRegistry::class)->approve($peerId, $peer->publicKey(), $url);
    }

    /**
     * @param  array<string, int>  $version
     */
    protected function signedEvent(
        KeyManager $peer,
        string $origin = 'uae',
        int $seq = 1,
        string $collection = 'users',
        string $recordId = 'usr_remote_1',
        array $data = ['name' => 'Remote'],
        array $version = ['uae' => 1],
        string $timestamp = '2026-11-12T10:00:00Z',
        string $type = Event::TYPE_CREATED,
    ): Event {
        $document = RecordDocument::make($collection, $recordId, $data, $version, $timestamp);

        $event = Event::compose($origin, $seq, $type, $document, $version, $timestamp, null);

        return $event->withSignature(
            (new MessageSigner($peer))->sign($event->signableContent()),
        );
    }
}
