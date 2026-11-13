<?php

namespace Tests\Feature\Security;

use App\DStore\Security\IdentityRegistry;
use Tests\TestCase;

class IdentityRegistryTest extends TestCase
{
    private function identities(): IdentityRegistry
    {
        return $this->app->make(IdentityRegistry::class);
    }

    public function test_the_identity_card_is_created_on_first_use(): void
    {
        $this->assertFalse($this->identities()->exists());

        $identity = $this->identities()->identity();

        $this->assertTrue($this->identities()->exists());
        $this->assertSame('test-node', $identity->nodeId);
        $this->assertFileExists($this->storagePath('metadata/node.json'));
    }

    public function test_the_identity_card_matches_the_stored_key_pair(): void
    {
        $identity = $this->identities()->identity();
        $keys = $this->app->make(\App\DStore\Security\KeyManager::class);

        $this->assertSame($keys->publicKey(), $identity->publicKey);
    }

    public function test_the_public_identity_never_contains_a_secret(): void
    {
        $identity = $this->identities()->identity();
        $keys = $this->app->make(\App\DStore\Security\KeyManager::class);
        $secret = $keys->secretKey();

        $encoded = json_encode($identity->toArray());

        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString((string) base64_decode($secret, true), $encoded);
        $this->assertArrayNotHasKey('secret', $identity->toArray());
        $this->assertArrayNotHasKey('private_key', $identity->toArray());
    }

    public function test_the_fingerprint_is_derived_from_the_public_key(): void
    {
        $identity = $this->identities()->identity();

        $this->assertSame(hash('sha256', $identity->publicKey), $identity->fingerprint());
        $this->assertSame(16, strlen($identity->shortFingerprint()));
    }

    public function test_rotating_the_identity_replaces_the_key_pair(): void
    {
        $first = $this->identities()->identity();

        $second = $this->identities()->rotate();

        $this->assertNotSame($first->publicKey, $second->publicKey);
        $this->assertSame(
            $this->app->make(\App\DStore\Security\KeyManager::class)->publicKey(),
            $second->publicKey,
        );
    }
}
