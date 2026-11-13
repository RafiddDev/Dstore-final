<?php

namespace Tests\Feature\Security;

use App\DStore\Security\Exceptions\UnauthorizedNodeException;
use App\DStore\Security\KeyManager;
use App\DStore\Security\MessageSigner;
use App\DStore\Security\NodeRegistrar;
use App\DStore\Security\PeerRegistry;
use App\DStore\Security\RegistrationProof;
use App\DStore\Storage\StoragePaths;
use Tests\TestCase;

class NodeRegistrarTest extends TestCase
{
    private function registrar(): NodeRegistrar
    {
        return $this->app->make(NodeRegistrar::class);
    }

    private function enrollingNode(): KeyManager
    {
        $manager = new KeyManager(
            new StoragePaths($this->storagePath('enrolling-node')),
            (string) config('app.key'),
        );

        $manager->publicKey();

        return $manager;
    }

    /**
     * @return array{public: string, signature: string, nonce: string}
     */
    private function proof(KeyManager $node, string $nodeId = 'uae', string $url = 'http://127.0.0.1:8002'): array
    {
        $nonce = bin2hex(random_bytes(16));

        $body = [
            'node_id' => $nodeId,
            'public_key' => $node->publicKey(),
            'url' => $url,
            'nonce' => $nonce,
        ];

        return [
            'public' => $node->publicKey(),
            'nonce' => $nonce,
            'signature' => (new MessageSigner($node))->signRaw(RegistrationProof::encode($body)),
        ];
    }

    private function useTokenMode(): void
    {
        config([
            'dstore.security.bootstrap_token' => 'enrol-me',
            'dstore.security.registration_mode' => 'token',
        ]);

        $this->refreshDStoreBindings();
    }

    public function test_a_node_with_the_bootstrap_token_is_enrolled(): void
    {
        $this->useTokenMode();

        $proof = $this->proof($this->enrollingNode());

        $peer = $this->registrar()->register(
            'uae',
            $proof['public'],
            'enrol-me',
            'http://127.0.0.1:8002',
            'UAE',
            '',
            $proof['nonce'],
            $proof['signature'],
        );

        $this->assertSame(PeerRegistry::STATUS_APPROVED, $peer['status']);
        $this->assertSame($proof['public'], $peer['public_key']);
        $this->assertTrue($this->app->make(PeerRegistry::class)->isTrusted('uae'));
    }

    public function test_a_node_without_the_bootstrap_token_is_refused(): void
    {
        $this->useTokenMode();

        $proof = $this->proof($this->enrollingNode());

        $this->expectException(UnauthorizedNodeException::class);

        $this->registrar()->register(
            'uae',
            $proof['public'],
            'wrong-token',
            'http://127.0.0.1:8002',
            'UAE',
            '',
            $proof['nonce'],
            $proof['signature'],
        );
    }

    public function test_a_node_that_cannot_prove_possession_of_its_key_is_refused(): void
    {
        $this->useTokenMode();

        $node = $this->enrollingNode();
        $proof = $this->proof($node);

        $this->expectException(UnauthorizedNodeException::class);

        // The signature was produced by a different key than the one presented.
        $this->registrar()->register(
            'uae',
            base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)),
            'enrol-me',
            'http://127.0.0.1:8002',
            'UAE',
            '',
            $proof['nonce'],
            $proof['signature'],
        );
    }

    public function test_a_missing_proof_is_refused(): void
    {
        $this->useTokenMode();

        $this->expectException(UnauthorizedNodeException::class);

        $this->registrar()->register('uae', $this->enrollingNode()->publicKey(), 'enrol-me');
    }

    public function test_a_node_with_a_malformed_public_key_is_refused(): void
    {
        $this->useTokenMode();

        $this->expectException(UnauthorizedNodeException::class);

        $this->registrar()->register('uae', base64_encode('too short'), 'enrol-me');
    }

    public function test_a_node_cannot_register_itself(): void
    {
        $this->useTokenMode();

        $proof = $this->proof($this->enrollingNode(), 'test-node');

        $this->expectException(UnauthorizedNodeException::class);

        $this->registrar()->register(
            'test-node',
            $proof['public'],
            'enrol-me',
            '',
            '',
            '',
            $proof['nonce'],
            $proof['signature'],
        );
    }

    public function test_an_empty_node_id_is_refused(): void
    {
        $this->useTokenMode();

        $this->expectException(UnauthorizedNodeException::class);

        $this->registrar()->register('', $this->enrollingNode()->publicKey(), 'enrol-me');
    }
}
