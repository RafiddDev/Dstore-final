<?php

namespace Tests\Feature\Network;

use App\DStore\Network\RegistrationService;
use App\DStore\Security\PeerRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrationServiceTest extends TestCase
{
    private function service(): RegistrationService
    {
        config([
            'dstore.peers' => ['uae' => ['id' => 'uae', 'url' => 'http://127.0.0.1:8002']],
            'dstore.security.bootstrap_token' => 'enrol-me',
            'dstore.security.registration_mode' => 'token',
        ]);
        $this->refreshDStoreBindings();

        return $this->app->make(RegistrationService::class);
    }

    public function test_enrolment_sends_the_identity_the_token_and_a_proof(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'node' => [
            'node_id' => 'uae',
            'public_key' => base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)),
            'fingerprint' => 'abc',
        ]], 200)]);

        $this->service()->enroll('uae');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode((string) $request->body(), true);

            return isset($body['node_id'])
                && $body['node_id'] === 'test-node'
                && $body['token'] === 'enrol-me'
                && isset($body['public_key'])
                && isset($body['nonce'])
                && isset($body['signature'])
                && $body['signature'] !== '';
        });
    }

    public function test_a_successful_enrolment_pins_the_peer_public_key(): void
    {
        $peerKey = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES));

        Http::fake(['*' => Http::response(['ok' => true, 'node' => [
            'node_id' => 'uae',
            'name' => 'UAE',
            'region' => 'middle-east',
            'url' => 'http://127.0.0.1:8002',
            'public_key' => $peerKey,
            'fingerprint' => hash('sha256', $peerKey),
        ]], 200)]);

        $response = $this->service()->enroll('uae');

        $this->assertTrue($response->successful);

        $registry = $this->app->make(PeerRegistry::class);

        $this->assertTrue($registry->isTrusted('uae'));
        $this->assertSame($peerKey, $registry->publicKeyFor('uae'));
    }

    public function test_a_refused_enrolment_leaves_the_peer_untrusted(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error' => 'bad token'], 403)]);

        $response = $this->service()->enroll('uae');

        $this->assertFalse($response->successful);
        $this->assertFalse($this->app->make(PeerRegistry::class)->isTrusted('uae'));
    }

    public function test_enrolling_with_an_unknown_peer_throws(): void
    {
        $this->expectException(\App\DStore\Network\Exceptions\NetworkException::class);

        $this->service()->enroll('nowhere');
    }
}
