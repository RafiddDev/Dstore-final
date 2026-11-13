<?php

namespace Tests\Feature\Security;

use App\DStore\Security\Exceptions\IdentityException;
use App\DStore\Security\KeyManager;
use App\DStore\Storage\StoragePaths;
use Tests\TestCase;

class KeyManagerTest extends TestCase
{
    private function manager(?string $appKey = null): KeyManager
    {
        return new KeyManager(
            $this->app->make(StoragePaths::class),
            $appKey ?? (string) config('app.key'),
        );
    }

    public function test_it_generates_a_valid_ed25519_key_pair(): void
    {
        $manager = $this->manager();
        $pair = $manager->generate();

        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen((string) base64_decode($pair['public'], true)));
        $this->assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen((string) base64_decode($pair['secret'], true)));
    }

    public function test_the_key_pair_is_created_on_first_use(): void
    {
        $manager = $this->manager();

        $this->assertFalse($manager->exists());

        $manager->publicKey();

        $this->assertTrue($manager->exists());
        $this->assertFileExists($this->storagePath('metadata/keys/node.key'));
    }

    public function test_the_secret_key_is_not_stored_in_plain_text(): void
    {
        $manager = $this->manager();
        $pair = $manager->generate();
        $manager->store($pair['public'], $pair['secret']);

        $raw = (string) file_get_contents($this->storagePath('metadata/keys/node.key'));

        $this->assertStringNotContainsString($pair['secret'], $raw);
        $this->assertStringNotContainsString((string) base64_decode($pair['secret'], true), $raw);
    }

    public function test_the_key_file_is_readable_only_by_the_owner(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows does not enforce Unix permission bits; this is verified on the Linux deployment target.');
        }

        $manager = $this->manager();
        $manager->publicKey();

        $permissions = fileperms($this->storagePath('metadata/keys/node.key')) & 0777;

        $this->assertSame(0600, $permissions);
    }

    public function test_a_key_stored_with_a_different_application_key_cannot_be_read(): void
    {
        $manager = $this->manager();
        $manager->publicKey();

        $other = $this->manager('base64:'.base64_encode(random_bytes(32)));

        $this->expectException(IdentityException::class);

        $other->publicKey();
    }

    public function test_the_stored_public_key_matches_the_secret_key(): void
    {
        $manager = $this->manager();
        $manager->publicKey();

        $public = base64_decode($manager->publicKey(), true);
        $secret = base64_decode($manager->secretKey(), true);

        $this->assertSame($public, sodium_crypto_sign_publickey_from_secretkey($secret));
    }

    public function test_rotating_the_key_changes_the_public_key(): void
    {
        $manager = $this->manager();
        $first = $manager->publicKey();

        $manager->rotate();

        $this->assertNotSame($first, $manager->publicKey());
    }
}
