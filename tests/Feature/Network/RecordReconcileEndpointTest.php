<?php

namespace Tests\Feature\Network;

use App\DStore\Http\Middleware\VerifyNodeSignature;
use App\DStore\Security\Envelope;
use App\DStore\Security\KeyManager;
use App\DStore\Security\MessageSigner;
use App\DStore\Security\PeerRegistry;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\StoragePaths;
use Tests\TestCase;

class RecordReconcileEndpointTest extends TestCase
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

    private function envelope(KeyManager $peer, array $payload, string $messageId = 'msg-1'): array
    {
        $envelope = new Envelope($messageId, 'uae', time(), bin2hex(random_bytes(8)), $payload);

        return $envelope->withSignature(
            (new MessageSigner($peer))->sign($envelope->signableContent()),
            'peer-fingerprint',
        )->toArray();
    }

    private function signedPost(string $uri, KeyManager $peer, array $payload, string $messageId = 'msg-1')
    {
        return $this->postJson($uri, ['envelope' => $this->envelope($peer, $payload, $messageId)]);
    }

    public function test_the_endpoint_requires_a_signature(): void
    {
        $this->postJson('/api/dstore/v1/records/reconcile', [])->assertStatus(401);
    }

    public function test_the_endpoint_reports_the_local_state_of_a_record(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $this->app->make(StorageEngineInterface::class)->create('users', ['name' => 'Local'], 'usr_x');

        $response = $this->signedPost('/api/dstore/v1/records/reconcile', $peer, [
            'collection' => 'users',
            'record_ids' => ['usr_x'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('collection', 'users');
        $response->assertJsonPath('records.0.record_id', 'usr_x');
        $response->assertJsonPath('records.0.found', true);
        $response->assertJsonPath('records.0.version.test-node', 1);
        $response->assertJsonPath('records.0.writer', 'test-node');
        $response->assertJsonPath('records.0.relation', 'unknown');
    }

    public function test_the_endpoint_compares_the_local_state_with_the_peers_claim(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $this->app->make(StorageEngineInterface::class)->create('users', ['name' => 'Local'], 'usr_x');

        $response = $this->signedPost('/api/dstore/v1/records/reconcile', $peer, [
            'collection' => 'users',
            'record_ids' => ['usr_x'],
            'peer_versions' => ['usr_x' => ['test-node' => 1, 'uae' => 2]],
        ]);

        $response->assertOk();
        $response->assertJsonPath('records.0.relation', 'peer-newer');
        $response->assertJsonPath('records.0.peer_version.uae', 2);
    }

    public function test_the_endpoint_rejects_an_empty_record_list(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $response = $this->signedPost('/api/dstore/v1/records/reconcile', $peer, [
            'collection' => 'users',
            'record_ids' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('ok', false);
    }

    public function test_the_endpoint_reports_the_policy_in_use(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $response = $this->signedPost('/api/dstore/v1/records/reconcile', $peer, [
            'collection' => 'users',
            'record_ids' => ['usr_x'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('policy', 'vector-lww');
        $this->assertIsArray($response->json('policies'));
        $this->assertContains('merge', $response->json('policies'));
    }

    public function test_the_endpoint_exposes_no_key_material(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $body = $this->signedPost('/api/dstore/v1/records/reconcile', $peer, [
            'collection' => 'users',
            'record_ids' => ['usr_x'],
        ])->getContent();

        $this->assertStringNotContainsString('private', $body);
        $this->assertStringNotContainsString('secret', $body);
        $this->assertStringNotContainsString('public_key', $body);
    }

    public function test_the_signature_header_is_accepted_as_well(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $response = $this->postJson('/api/dstore/v1/records/reconcile', [], [
            VerifyNodeSignature::ENVELOPE_HEADER => base64_encode(json_encode($this->envelope($peer, [
                'collection' => 'users',
                'record_ids' => ['usr_x'],
            ]))),
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
    }
}
