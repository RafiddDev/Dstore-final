<?php

namespace App\DStore\Security;

use App\DStore\Security\Exceptions\IdentityException;
use App\DStore\Storage\AtomicFile;
use App\DStore\Storage\StoragePaths;
use App\DStore\Support\CanonicalJson;
use App\DStore\Support\Timestamp;

/**
 * Owns this node's Ed25519 key pair.
 *
 * The secret key never leaves this class and is never returned to a caller
 * outside of {@see sign()} / {@see secretKeyBytes()}, which stay server side.
 * At rest it is encrypted with a key derived from the application key, so a
 * copied storage directory is useless without APP_KEY.
 */
final class KeyManager
{
    private const DERIVATION_INFO = 'dstore:node-secret-key:v1';

    public function __construct(
        private readonly StoragePaths $paths,
        private readonly string $appKey,
    ) {}

    public function exists(): bool
    {
        return is_file($this->paths->secretKeyFile());
    }

    /**
     * Creates the key pair if it is missing, so a node can start serving
     * records without a separate bootstrap step.
     */
    public function ensureExists(): void
    {
        if ($this->exists()) {
            return;
        }

        $pair = $this->generate();

        $this->store($pair['public'], $pair['secret']);
    }

    /**
     * @return array{public: string, secret: string} base64 encoded key pair
     */
    public function generate(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    public function publicKey(): string
    {
        return $this->keyPair()['public'];
    }

    public function secretKey(): string
    {
        return $this->keyPair()['secret'];
    }

    public function secretKeyBytes(): string
    {
        $decoded = base64_decode($this->secretKey(), true);

        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new IdentityException('Stored secret key is malformed.');
        }

        return $decoded;
    }

    public function publicKeyBytes(): string
    {
        $decoded = base64_decode($this->publicKey(), true);

        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new IdentityException('Stored public key is malformed.');
        }

        return $decoded;
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->publicKey());
    }

    public function store(string $publicKey, string $secretKey): void
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($secretKey, $nonce, $this->encryptionKey());

        AtomicFile::write($this->paths->secretKeyFile(), CanonicalJson::encode([
            'alg' => 'xsalsa20poly1305',
            'nonce' => base64_encode($nonce),
            'cipher' => base64_encode($cipher),
            'created_at' => Timestamp::now(),
        ]));

        @chmod($this->paths->secretKeyFile(), 0600);
    }

    public function rotate(): array
    {
        $pair = $this->generate();

        $this->store($pair['public'], $pair['secret']);

        return $pair;
    }

    private function keyPair(): array
    {
        $this->ensureExists();

        $path = $this->paths->secretKeyFile();

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new IdentityException("Node key file is not readable: {$path}");
        }

        $nonce = base64_decode((string) ($decoded['nonce'] ?? ''), true);
        $cipher = base64_decode((string) ($decoded['cipher'] ?? ''), true);

        if ($nonce === false || $cipher === false) {
            throw new IdentityException("Node key file is malformed: {$path}");
        }

        $secret = sodium_crypto_secretbox_open($cipher, $nonce, $this->encryptionKey());

        if ($secret === false) {
            throw new IdentityException(
                'Unable to decrypt the node secret key. The application key does not match this storage directory.'
            );
        }

        $secretBytes = base64_decode($secret, true);

        if ($secretBytes === false || strlen($secretBytes) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new IdentityException('Decrypted secret key has an unexpected length.');
        }

        $publicBytes = sodium_crypto_sign_publickey_from_secretkey($secretBytes);

        return [
            'public' => base64_encode($publicBytes),
            'secret' => base64_encode($secretBytes),
        ];
    }

    private function encryptionKey(): string
    {
        $raw = $this->appKey;

        if ($raw === '') {
            throw new IdentityException(
                'APP_KEY is not set. A node key pair cannot be encrypted at rest without it.'
            );
        }

        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);

            if ($decoded === false) {
                throw new IdentityException('APP_KEY is not valid base64.');
            }

            $raw = $decoded;
        }

        return hash_hkdf('sha256', $raw, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::DERIVATION_INFO);
    }
}
