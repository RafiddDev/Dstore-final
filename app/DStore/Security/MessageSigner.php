<?php

namespace App\DStore\Security;

use App\DStore\Security\Exceptions\SignatureVerificationException;
use App\DStore\Support\CanonicalJson;

/**
 * Ed25519 detached signatures over canonical JSON.
 */
final class MessageSigner
{
    public function __construct(private readonly KeyManager $keys) {}

    public function sign(array $payload): string
    {
        return base64_encode(sodium_crypto_sign_detached(
            CanonicalJson::encode($payload),
            $this->keys->secretKeyBytes(),
        ));
    }

    public function signRaw(string $message): string
    {
        return base64_encode(sodium_crypto_sign_detached(
            $message,
            $this->keys->secretKeyBytes(),
        ));
    }

    public function verify(array $payload, string $signature, string $publicKey): bool
    {
        return $this->verifyRaw(CanonicalJson::encode($payload), $signature, $publicKey);
    }

    public function verifyRaw(string $message, string $signature, string $publicKey): bool
    {
        $signatureBytes = base64_decode($signature, true);
        $publicKeyBytes = base64_decode($publicKey, true);

        if ($signatureBytes === false || $publicKeyBytes === false) {
            return false;
        }

        if (strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        if (strlen($publicKeyBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signatureBytes, $message, $publicKeyBytes);
    }

    public function assertValid(array $payload, string $signature, string $publicKey): void
    {
        if (! $this->verify($payload, $signature, $publicKey)) {
            throw new SignatureVerificationException('Message signature verification failed.');
        }
    }
}
