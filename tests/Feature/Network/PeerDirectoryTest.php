<?php

namespace Tests\Feature\Network;

use App\DStore\Network\PeerDirectory;
use App\DStore\Security\PeerRegistry;
use App\DStore\Storage\StoragePaths;
use Tests\TestCase;

class PeerDirectoryTest extends TestCase
{
    private function directory(array $configured = [], array $registryPeers = []): PeerDirectory
    {
        if ($registryPeers !== []) {
            $path = $this->storagePath('metadata/peers.json');
            \App\DStore\Storage\AtomicFile::write($path, json_encode($registryPeers));
        }

        return new PeerDirectory(
            new PeerRegistry($this->app->make(StoragePaths::class), $configured),
            $configured,
        );
    }

    public function test_configured_peers_are_listed_even_before_they_are_trusted(): void
    {
        $directory = $this->directory(['uae' => ['id' => 'uae', 'url' => 'http://127.0.0.1:8002']]);

        $peer = $directory->find('uae');

        $this->assertNotNull($peer);
        $this->assertSame('uae', $peer->id);
        $this->assertSame('http://127.0.0.1:8002', $peer->url);
        $this->assertFalse($peer->trusted);
    }

    public function test_approved_peers_are_marked_trusted(): void
    {
        $key = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES));

        $directory = $this->directory(['uae' => ['id' => 'uae', 'url' => 'http://127.0.0.1:8002']], [
            'uae' => [
                'id' => 'uae',
                'name' => 'UAE',
                'url' => 'http://127.0.0.1:8002',
                'region' => '',
                'public_key' => $key,
                'fingerprint' => hash('sha256', $key),
                'status' => PeerRegistry::STATUS_APPROVED,
                'enrolled_at' => null,
                'approved_at' => null,
                'revoked_at' => null,
            ],
        ]);

        $this->assertTrue($directory->find('uae')->trusted);
        $this->assertCount(1, $directory->trusted());
    }

    public function test_a_revoked_peer_is_not_trusted(): void
    {
        $key = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES));

        $directory = $this->directory([], [
            'uae' => [
                'id' => 'uae',
                'name' => 'UAE',
                'url' => 'http://127.0.0.1:8002',
                'region' => '',
                'public_key' => $key,
                'fingerprint' => hash('sha256', $key),
                'status' => PeerRegistry::STATUS_REVOKED,
                'enrolled_at' => null,
                'approved_at' => null,
                'revoked_at' => null,
            ],
        ]);

        $this->assertFalse($directory->find('uae')->trusted);
        $this->assertCount(0, $directory->trusted());
    }

    public function test_an_unknown_peer_cannot_be_required(): void
    {
        $this->expectException(\App\DStore\Network\Exceptions\NetworkException::class);

        $this->directory()->require('nowhere');
    }

    public function test_the_endpoint_url_is_built_from_the_peer_url(): void
    {
        $peer = $this->directory(['uae' => ['id' => 'uae', 'url' => 'http://127.0.0.1:8002/']])
            ->find('uae');

        $this->assertSame('http://127.0.0.1:8002/api/dstore/v1/health', $peer->endpoint('/api/dstore/v1/health'));
    }
}
