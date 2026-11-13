<?php

namespace Tests\Feature\Security;

use App\DStore\Security\KeyManager;
use App\DStore\Security\MessageSigner;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\EventLog;
use Tests\TestCase;

class EventSigningTest extends TestCase
{
    public function test_events_produced_by_the_engine_are_signed(): void
    {
        $this->app->make(StorageEngineInterface::class)->create('users', ['name' => 'Ada']);

        $events = iterator_to_array($this->app->make(EventLog::class)->all());

        $this->assertCount(1, $events);
        $this->assertNotNull($events[0]->signature);
        $this->assertNotSame('', $events[0]->signature);
    }

    public function test_the_event_signature_verifies_against_the_node_public_key(): void
    {
        $this->app->make(StorageEngineInterface::class)->create('users', ['name' => 'Ada']);

        $event = iterator_to_array($this->app->make(EventLog::class)->all())[0];

        $signer = new MessageSigner($this->app->make(KeyManager::class));
        $publicKey = $this->app->make(KeyManager::class)->publicKey();

        $this->assertTrue($signer->verify($event->signableContent(), (string) $event->signature, $publicKey));
    }

    public function test_a_tampered_event_body_fails_signature_verification(): void
    {
        $this->app->make(StorageEngineInterface::class)->create('users', ['name' => 'Ada']);

        $event = iterator_to_array($this->app->make(EventLog::class)->all())[0];

        $signer = new MessageSigner($this->app->make(KeyManager::class));
        $publicKey = $this->app->make(KeyManager::class)->publicKey();

        $this->assertTrue($signer->verify($event->signableContent(), (string) $event->signature, $publicKey));

        $tampered = $event->signableContent();
        $tampered['payload']['data']['name'] = 'Mallory';

        $this->assertFalse($signer->verify($tampered, (string) $event->signature, $publicKey));
    }

    public function test_signing_does_not_change_the_event_id_or_content_hash(): void
    {
        $this->app->make(StorageEngineInterface::class)->create('users', ['name' => 'Ada']);

        $event = iterator_to_array($this->app->make(EventLog::class)->all())[0];

        $this->assertStringStartsWith('test-node-00000001-', $event->eventId);
        $this->assertSame(
            $event->contentHash(),
            hash('sha256', \App\DStore\Support\CanonicalJson::encode($event->signableContent())),
        );
    }

    public function test_the_copy_queued_for_a_peer_keeps_the_signature(): void
    {
        config(['dstore.peers' => ['uae' => ['id' => 'uae', 'url' => 'http://127.0.0.1:8002']]]);
        $this->refreshDStoreBindings();

        $this->app->make(StorageEngineInterface::class)->create('users', ['name' => 'Ada']);

        $queued = $this->app->make(EventLog::class)->pendingEvents('uae');

        $this->assertCount(1, $queued);
        $this->assertNotNull($queued[0]->signature);
    }
}
