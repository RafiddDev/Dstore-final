<?php

namespace Tests\Feature\Network;

use App\DStore\Http\Middleware\VerifyNodeSignature;
use App\DStore\Security\Envelope;
use App\DStore\Security\KeyManager;
use App\DStore\Security\MessageSigner;
use App\DStore\Security\PeerRegistry;
use App\DStore\Security\RegistrationProof;
use App\DStore\Storage\Event;
use App\DStore\Storage\EventInbox;
use App\DStore\Storage\EventLog;
use App\DStore\Storage\RecordDocument;
use App\DStore\Storage\StoragePaths;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use Tests\TestCase;

class ApiEndpointsTest extends TestCase
{
    private function peerKeyManager(string $name = 'peer-uae'): KeyManager
    {
        $manager = new KeyManager(
            new StoragePaths($this->storagePath($name)),
            (string) config('app.key'),
        );

        $manager->publicKey();

        return $manager;
    }

    private function approvePeer(KeyManager $peer, string $peerId = 'uae'): void
    {
        $this->app->make(PeerRegistry::class)->approve($peerId, $peer->publicKey(), 'http://127.0.0.1:8002');
    }

    private function envelope(KeyManager $peer, array $payload = [], string $messageId = 'msg-1'): array
    {
        $envelope = new Envelope($messageId, 'uae', time(), bin2hex(random_bytes(8)), $payload);

        return $envelope->withSignature(
            (new MessageSigner($peer))->sign($envelope->signableContent()),
            'peer-fingerprint',
        )->toArray();
    }

    /**
     * A GET request cannot carry a body, so the envelope travels in the header.
     */
    private function signedGet(string $uri, KeyManager $peer, array $payload = [], string $messageId = 'msg-1')
    {
        return $this->getJson($uri, [
            VerifyNodeSignature::ENVELOPE_HEADER => base64_encode(json_encode($this->envelope($peer, $payload, $messageId))),
        ]);
    }

    private function signedEvent(KeyManager $peer, string $origin = 'uae', int $seq = 1): array
    {
        $document = RecordDocument::make('users', 'usr_remote_'.$seq, ['name' => 'Remote'], [$origin => 1], '2026-11-12T10:00:00Z');

        $event = Event::compose($origin, $seq, Event::TYPE_CREATED, $document, [$origin => 1], '2026-11-12T10:00:00Z', null);

        return $event->withSignature(
            (new MessageSigner($peer))->sign($event->signableContent()),
        )->toArray();
    }

    public function test_the_health_endpoint_is_reachable_without_a_signature(): void
    {
        $response = $this->getJson('/api/dstore/v1/health');

        $response->assertOk();
        $response->assertJson(['ok' => true, 'node_id' => 'test-node']);
    }

    public function test_the_health_endpoint_exposes_no_key_material(): void
    {
        $body = $this->getJson('/api/dstore/v1/health')->getContent();

        $this->assertStringNotContainsString('private', $body);
        $this->assertStringNotContainsString('secret', $body);
        $this->assertStringNotContainsString('public_key', $body);
    }

    public function test_the_node_list_requires_a_signature(): void
    {
        $this->getJson('/api/dstore/v1/nodes')->assertStatus(401);
    }

    public function test_the_node_list_is_returned_to_an_approved_peer(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $response = $this->signedGet('/api/dstore/v1/nodes', $peer);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $this->assertIsArray($response->json('nodes'));
    }

    public function test_the_heartbeat_carries_the_applied_vector(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $response = $this->postJson('/api/dstore/v1/heartbeat', ['envelope' => $this->envelope($peer)]);

        $response->assertOk();
        $response->assertJsonPath('node_id', 'test-node');
        $this->assertIsArray($response->json('applied_vector'));
    }

    public function test_a_signed_event_from_a_trusted_peer_is_accepted(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $response = $this->postJson('/api/dstore/v1/events/push', [
            'envelope' => $this->envelope($peer, ['events' => [$this->signedEvent($peer)]]),
        ]);

        $response->assertOk();
        $response->assertJson(['accepted' => 1, 'duplicates' => 0]);
        $response->assertJsonPath('applied_vector.uae', 1);

        // The event was applied, so it is no longer waiting in the inbox.
        $this->assertSame(0, $this->app->make(EventInbox::class)->count());

        $record = $this->app->make(StorageEngineInterface::class)->read('users', 'usr_remote_1');

        $this->assertNotNull($record);
        $this->assertSame('Remote', $record->data['name']);
        $this->assertSame(['uae' => 1], $record->version());
    }

    public function test_the_same_event_pushed_twice_is_stored_once(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $event = $this->signedEvent($peer);

        $this->postJson('/api/dstore/v1/events/push', [
            'envelope' => $this->envelope($peer, ['events' => [$event]], 'msg-1'),
        ])->assertJson(['accepted' => 1]);

        $this->postJson('/api/dstore/v1/events/push', [
            'envelope' => $this->envelope($peer, ['events' => [$event]], 'msg-2'),
        ])->assertJson(['accepted' => 0, 'duplicates' => 1]);

        $this->assertSame(0, $this->app->make(EventInbox::class)->count());
        $this->assertCount(1, iterator_to_array($this->app->make(EventLog::class)->all()));
    }

    public function test_an_event_from_an_untrusted_origin_is_rejected(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $response = $this->postJson('/api/dstore/v1/events/push', [
            'envelope' => $this->envelope($peer, ['events' => [$this->signedEvent($peer, 'usa', 1)]]),
        ]);

        $response->assertOk();
        $response->assertJson(['accepted' => 0]);
        $this->assertCount(1, $response->json('rejected'));
        $this->assertSame(0, $this->app->make(EventInbox::class)->count());
    }

    public function test_an_event_with_a_broken_signature_is_rejected(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $event = $this->signedEvent($peer);
        $event['payload']['data']['name'] = 'Mallory';

        $response = $this->postJson('/api/dstore/v1/events/push', [
            'envelope' => $this->envelope($peer, ['events' => [$event]]),
        ]);

        $response->assertOk();
        $response->assertJson(['accepted' => 0]);
        $this->assertSame('invalid event signature', $response->json('rejected.0.reason'));
        $this->assertSame(0, $this->app->make(EventInbox::class)->count());
    }

    public function test_events_are_pulled_incrementally(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $this->app->make(EventLog::class)->append(
            Event::fromArray($this->signedEvent($peer, 'uae', 1))
        );

        $response = $this->signedGet('/api/dstore/v1/events', $peer, [], 'msg-1');

        $response->assertOk();
        $this->assertCount(1, $response->json('events'));

        $after = $this->signedGet('/api/dstore/v1/events?after=uae:1', $peer, [], 'msg-2');

        $this->assertCount(0, $after->json('events'));
    }

    public function test_a_pushed_event_is_durable_before_it_is_applied(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $this->postJson('/api/dstore/v1/events/push', [
            'envelope' => $this->envelope($peer, ['events' => [$this->signedEvent($peer, 'uae', 1)]]),
        ])->assertJson(['accepted' => 1]);

        $inbox = $this->app->make(EventInbox::class);

        // Applied, so nothing is left waiting.
        $this->assertCount(0, $inbox->pending());
        $this->assertSame(0, $inbox->count());

        // It is in the committed log, so it can be offered to the other peers.
        $this->assertCount(1, iterator_to_array($this->app->make(EventLog::class)->all()));
    }

    public function test_a_pushed_event_is_applied_to_the_local_records(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $this->postJson('/api/dstore/v1/events/push', [
            'envelope' => $this->envelope($peer, ['events' => [$this->signedEvent($peer, 'uae', 1)]]),
        ])->assertJson(['accepted' => 1]);

        $record = $this->app->make(StorageEngineInterface::class)->read('users', 'usr_remote_1');

        $this->assertNotNull($record);
        $this->assertSame(['name' => 'Remote'], $record->data);
        $this->assertSame('uae', $record->writer());
    }

    public function test_the_sync_status_reports_pending_work(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $response = $this->signedGet('/api/dstore/v1/sync/status', $peer);

        $response->assertOk();
        $response->assertJsonPath('node_id', 'test-node');
    }

    public function test_registration_succeeds_with_a_token_and_a_proof_of_possession(): void
    {
        config([
            'dstore.security.bootstrap_token' => 'enrol-me',
            'dstore.security.registration_mode' => 'token',
        ]);
        $this->refreshDStoreBindings();

        $peer = $this->peerKeyManager();
        $nonce = bin2hex(random_bytes(16));

        $body = [
            'node_id' => 'uae',
            'public_key' => $peer->publicKey(),
            'url' => 'http://127.0.0.1:8002',
            'nonce' => $nonce,
        ];

        $body['signature'] = (new MessageSigner($peer))->signRaw(RegistrationProof::encode($body));

        $response = $this->postJson('/api/dstore/v1/nodes/register', $body + ['token' => 'enrol-me']);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $this->assertTrue($this->app->make(PeerRegistry::class)->isTrusted('uae'));
    }

    public function test_registration_is_refused_without_the_bootstrap_token(): void
    {
        config([
            'dstore.security.bootstrap_token' => 'enrol-me',
            'dstore.security.registration_mode' => 'token',
        ]);
        $this->refreshDStoreBindings();

        $peer = $this->peerKeyManager();
        $nonce = bin2hex(random_bytes(16));

        $body = [
            'node_id' => 'uae',
            'public_key' => $peer->publicKey(),
            'url' => 'http://127.0.0.1:8002',
            'nonce' => $nonce,
        ];

        $body['signature'] = (new MessageSigner($peer))->signRaw(RegistrationProof::encode($body));

        $response = $this->postJson('/api/dstore/v1/nodes/register', $body + ['token' => 'wrong']);

        $response->assertStatus(403);
        $this->assertFalse($this->app->make(PeerRegistry::class)->isTrusted('uae'));
    }

    public function test_registration_is_refused_without_a_proof_of_possession(): void
    {
        config([
            'dstore.security.bootstrap_token' => 'enrol-me',
            'dstore.security.registration_mode' => 'token',
        ]);
        $this->refreshDStoreBindings();

        $peer = $this->peerKeyManager();

        $response = $this->postJson('/api/dstore/v1/nodes/register', [
            'node_id' => 'uae',
            'public_key' => $peer->publicKey(),
            'url' => 'http://127.0.0.1:8002',
            'nonce' => bin2hex(random_bytes(16)),
            'token' => 'enrol-me',
        ]);

        $response->assertStatus(403);
        $this->assertFalse($this->app->make(PeerRegistry::class)->isTrusted('uae'));
    }

    public function test_an_unsigned_request_to_a_signed_endpoint_is_refused(): void
    {
        $this->postJson('/api/dstore/v1/heartbeat', [])->assertStatus(401);
        $this->postJson('/api/dstore/v1/events/push', [])->assertStatus(401);
    }
}
