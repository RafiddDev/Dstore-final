<?php

namespace Tests\Feature\Monitoring;

use App\DStore\Http\Middleware\VerifyNodeSignature;
use App\DStore\Monitoring\PeerStatusStore;
use App\DStore\Security\Envelope;
use App\DStore\Security\KeyManager;
use App\DStore\Security\MessageSigner;
use Tests\Support\BuildsPeerEvents;
use Tests\TestCase;

class MonitoringApiTest extends TestCase
{
    use BuildsPeerEvents;

    private function envelope(KeyManager $peer, array $payload = [], string $messageId = 'msg-1'): array
    {
        $envelope = new Envelope($messageId, 'uae', time(), bin2hex(random_bytes(8)), $payload);

        return $envelope->withSignature(
            (new MessageSigner($peer))->sign($envelope->signableContent()),
            'peer-fingerprint',
        )->toArray();
    }

    private function signedGet(string $uri, KeyManager $peer, string $messageId = 'msg-get'): \Illuminate\Testing\TestResponse
    {
        return $this->getJson($uri, [
            VerifyNodeSignature::ENVELOPE_HEADER => base64_encode(json_encode(
                $this->envelope($peer, [], $messageId)
            )),
        ]);
    }

    public function test_the_health_endpoint_reports_epoch_and_uptime(): void
    {
        $response = $this->getJson('/api/dstore/v1/health');

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('storage_ok', true);
        $response->assertJsonStructure([
            'node_id',
            'status',
            'degraded',
            'storage_ok',
            'records',
            'events_applied',
            'storage_bytes',
            'epoch',
            'started_at',
            'uptime_seconds',
        ]);

        $this->assertNotSame('', $response->json('epoch'));
        $this->assertIsInt($response->json('uptime_seconds'));
    }

    public function test_a_signed_heartbeat_updates_the_senders_status_and_returns_our_epoch(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer, 'uae');

        $response = $this->postJson('/api/dstore/v1/heartbeat', [
            'envelope' => $this->envelope($peer, [
                'epoch' => 'remote-epoch',
                'started_at' => '2026-11-13T00:00:00Z',
                'records' => 5,
                'events_committed' => 9,
                'storage_bytes' => 1024,
                'degraded' => false,
                'name' => 'UAE',
                'region' => 'middle-east',
                'url' => 'http://127.0.0.1:8002',
            ], 'msg-hb'),
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('degraded', false);
        $response->assertJsonStructure([
            'node_id',
            'epoch',
            'started_at',
            'uptime_seconds',
            'applied_vector',
            'records',
            'storage_bytes',
            'time',
        ]);
        $this->assertNotSame('', $response->json('epoch'));

        $status = $this->app->make(PeerStatusStore::class)->get('uae');
        $this->assertSame(PeerStatusStore::STATE_ONLINE, $status['state']);
        $this->assertSame('remote-epoch', $status['epoch']);
        $this->assertSame(5, $status['records']);
        $this->assertSame(1024, $status['storage_bytes']);
    }

    public function test_a_signed_heartbeat_that_reports_corruption_marks_the_sender_degraded(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer, 'uae');

        $this->postJson('/api/dstore/v1/heartbeat', [
            'envelope' => $this->envelope($peer, [
                'epoch' => 'remote-epoch',
                'degraded' => true,
            ], 'msg-hb-degraded'),
        ])->assertOk();

        $status = $this->app->make(PeerStatusStore::class)->get('uae');

        $this->assertSame(PeerStatusStore::STATE_DEGRADED, $status['state']);
        $this->assertTrue($status['degraded']);
    }

    public function test_the_node_list_exposes_the_detected_state_not_the_raw_state(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer, 'uae');

        $statuses = $this->app->make(PeerStatusStore::class);
        $statuses->recordFailure('uae', 'timeout');
        $statuses->recordFailure('uae', 'timeout');
        $statuses->recordFailure('uae', 'timeout');

        $response = $this->signedGet('/api/dstore/v1/nodes', $peer, 'msg-nodes');

        $response->assertOk();
        $response->assertJsonPath('nodes.0.node_id', 'uae');
        $response->assertJsonPath('nodes.0.state', PeerStatusStore::STATE_OFFLINE);
        $response->assertJsonPath('nodes.0.reachable', false);
        $response->assertJsonPath('nodes.0.consecutive_failures', 3);
    }
}
