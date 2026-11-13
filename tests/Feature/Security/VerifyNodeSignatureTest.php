<?php

namespace Tests\Feature\Security;

use App\DStore\Http\Middleware\VerifyNodeSignature;
use App\DStore\Security\Envelope;
use App\DStore\Security\KeyManager;
use App\DStore\Security\MessageSigner;
use App\DStore\Security\PeerRegistry;
use App\DStore\Storage\StoragePaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VerifyNodeSignatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(VerifyNodeSignature::class)->post('/dstore-test/push', function (Request $request) {
            $envelope = $request->attributes->get('dstore_envelope');

            return response()->json(['ok' => true, 'origin' => $envelope->originNode]);
        });
    }

    private function peerKeyManager(): KeyManager
    {
        $manager = new KeyManager(
            new StoragePaths($this->storagePath('peer-uae')),
            (string) config('app.key'),
        );

        $manager->publicKey();

        return $manager;
    }

    private function envelope(KeyManager $peer, string $messageId = 'msg-1'): array
    {
        $envelope = new Envelope($messageId, 'uae', time(), bin2hex(random_bytes(8)), ['ping' => 1]);
        $signature = (new MessageSigner($peer))->sign($envelope->signableContent());

        return $envelope->withSignature($signature, 'peer-fingerprint')->toArray();
    }

    public function test_a_signed_request_from_an_approved_peer_passes(): void
    {
        $peer = $this->peerKeyManager();
        $this->app->make(PeerRegistry::class)->approve('uae', $peer->publicKey());

        $response = $this->postJson('/dstore-test/push', ['envelope' => $this->envelope($peer)]);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'origin' => 'uae']);
    }

    public function test_the_envelope_may_be_sent_as_a_header(): void
    {
        $peer = $this->peerKeyManager();
        $this->app->make(PeerRegistry::class)->approve('uae', $peer->publicKey());

        $response = $this->postJson('/dstore-test/push', [], [
            VerifyNodeSignature::ENVELOPE_HEADER => base64_encode(json_encode($this->envelope($peer))),
        ]);

        $response->assertOk();
        $response->assertJson(['origin' => 'uae']);
    }

    public function test_a_request_without_an_envelope_is_refused(): void
    {
        $this->postJson('/dstore-test/push', [])->assertStatus(401);
    }

    public function test_a_request_from_an_unknown_node_is_refused(): void
    {
        $peer = $this->peerKeyManager();

        $this->postJson('/dstore-test/push', ['envelope' => $this->envelope($peer)])->assertStatus(403);
    }

    public function test_a_replayed_request_is_refused(): void
    {
        $peer = $this->peerKeyManager();
        $this->app->make(PeerRegistry::class)->approve('uae', $peer->publicKey());

        $envelope = $this->envelope($peer);

        $this->postJson('/dstore-test/push', ['envelope' => $envelope])->assertOk();
        $this->postJson('/dstore-test/push', ['envelope' => $envelope])->assertStatus(409);
    }

    public function test_a_tampered_request_is_refused(): void
    {
        $peer = $this->peerKeyManager();
        $this->app->make(PeerRegistry::class)->approve('uae', $peer->publicKey());

        $envelope = $this->envelope($peer);
        $envelope['payload']['ping'] = 999;

        $this->postJson('/dstore-test/push', ['envelope' => $envelope])->assertStatus(401);
    }
}
