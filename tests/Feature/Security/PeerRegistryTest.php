<?php

namespace Tests\Feature\Security;

use App\DStore\Security\Exceptions\UnauthorizedNodeException;
use App\DStore\Security\PeerRegistry;
use App\DStore\Storage\StoragePaths;
use Tests\TestCase;

class PeerRegistryTest extends TestCase
{
    private function registry(array $configured = [], bool $allowRekey = false): PeerRegistry
    {
        return new PeerRegistry($this->app->make(StoragePaths::class), $configured, $allowRekey);
    }

    private function publicKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES));
    }

    public function test_configured_peers_start_out_pending(): void
    {
        $registry = $this->registry(['uae' => ['id' => 'uae', 'url' => 'http://127.0.0.1:8002']]);

        $peer = $registry->get('uae');

        $this->assertNotNull($peer);
        $this->assertSame(PeerRegistry::STATUS_PENDING, $peer['status']);
        $this->assertNull($peer['public_key']);
        $this->assertFalse($registry->isTrusted('uae'));
    }

    public function test_approving_a_peer_pins_its_public_key(): void
    {
        $registry = $this->registry(['uae' => ['id' => 'uae', 'url' => 'http://127.0.0.1:8002']]);
        $key = $this->publicKey();

        $registry->approve('uae', $key, 'http://127.0.0.1:8002');

        $this->assertTrue($registry->isTrusted('uae'));
        $this->assertSame($key, $registry->publicKeyFor('uae'));
        $this->assertSame(hash('sha256', $key), $registry->fingerprintFor('uae'));
    }

    public function test_the_trust_store_survives_a_restart(): void
    {
        $key = $this->publicKey();

        $this->registry()->approve('uae', $key);

        $this->assertTrue($this->registry()->isTrusted('uae'));
    }

    public function test_an_approved_peer_cannot_silently_swap_its_key(): void
    {
        $registry = $this->registry();
        $registry->approve('uae', $this->publicKey());

        $this->expectException(UnauthorizedNodeException::class);

        $registry->approve('uae', $this->publicKey());
    }

    public function test_a_malformed_public_key_is_refused(): void
    {
        $this->expectException(UnauthorizedNodeException::class);

        $this->registry()->approve('uae', base64_encode('too short'));
    }

    public function test_a_rekey_is_allowed_when_explicitly_enabled(): void
    {
        $registry = $this->registry([], true);
        $first = $this->publicKey();
        $second = $this->publicKey();

        $registry->approve('uae', $first);
        $registry->approve('uae', $second);

        $this->assertTrue($registry->isTrusted('uae'));
        $this->assertSame($second, $registry->publicKeyFor('uae'));
    }

    public function test_revoking_a_peer_removes_its_trust(): void
    {
        $registry = $this->registry();
        $registry->approve('uae', $this->publicKey());

        $this->assertTrue($registry->revoke('uae'));
        $this->assertFalse($registry->isTrusted('uae'));
        $this->assertNull($registry->publicKeyFor('uae'));
    }

    public function test_revoking_an_unknown_peer_returns_false(): void
    {
        $this->assertFalse($this->registry()->revoke('nowhere'));
    }
}
