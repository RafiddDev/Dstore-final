<?php

namespace Tests\Feature\Security;

use App\DStore\Security\KeyManager;
use App\DStore\Security\MessageSigner;
use App\DStore\Storage\StoragePaths;
use Tests\TestCase;

class MessageSignerTest extends TestCase
{
    private function signer(): MessageSigner
    {
        return new MessageSigner($this->keys());
    }

    private function keys(): KeyManager
    {
        return new KeyManager($this->app->make(StoragePaths::class), (string) config('app.key'));
    }

    public function test_a_signed_payload_verifies_against_the_signing_key(): void
    {
        $signer = $this->signer();
        $payload = ['type' => 'record.created', 'record_id' => 'usr_1'];

        $signature = $signer->sign($payload);

        $this->assertTrue($signer->verify($payload, $signature, $this->keys()->publicKey()));
    }

    public function test_a_tampered_payload_fails_verification(): void
    {
        $signer = $this->signer();
        $payload = ['type' => 'record.created', 'record_id' => 'usr_1'];

        $signature = $signer->sign($payload);

        $this->assertFalse($signer->verify(
            ['type' => 'record.created', 'record_id' => 'usr_2'],
            $signature,
            $this->keys()->publicKey(),
        ));
    }

    public function test_a_signature_from_another_key_is_rejected(): void
    {
        $foreign = new KeyManager(
            new StoragePaths($this->storagePath('foreign')),
            (string) config('app.key'),
        );

        $signature = (new MessageSigner($foreign))->sign(['a' => 1]);

        $this->assertFalse($this->signer()->verify(['a' => 1], $signature, $this->keys()->publicKey()));
    }

    public function test_malformed_signatures_are_rejected_without_throwing(): void
    {
        $signer = $this->signer();
        $publicKey = $this->keys()->publicKey();

        $this->assertFalse($signer->verify(['a' => 1], 'not-base64!!', $publicKey));
        $this->assertFalse($signer->verify(['a' => 1], base64_encode('too short'), $publicKey));
        $this->assertFalse($signer->verify(['a' => 1], base64_encode(random_bytes(64)), $publicKey));
        $this->assertFalse($signer->verify(['a' => 1], base64_encode(random_bytes(64)), 'not-a-key'));
    }

    public function test_the_key_order_in_a_payload_does_not_change_the_verification(): void
    {
        $signer = $this->signer();
        $publicKey = $this->keys()->publicKey();

        $first = $signer->sign(['a' => 1, 'b' => 2]);
        $second = $signer->sign(['b' => 2, 'a' => 1]);

        $this->assertTrue($signer->verify(['a' => 1, 'b' => 2], $first, $publicKey));
        $this->assertTrue($signer->verify(['b' => 2, 'a' => 1], $second, $publicKey));
    }
}
