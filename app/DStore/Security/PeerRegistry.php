<?php

namespace App\DStore\Security;

use App\DStore\Security\Exceptions\UnauthorizedNodeException;
use App\DStore\Storage\AtomicFile;
use App\DStore\Storage\StoragePaths;
use App\DStore\Support\CanonicalJson;
use App\DStore\Support\Timestamp;

/**
 * The trust store of known peers.
 *
 * A peer becomes trusted only when its Ed25519 public key has been pinned
 * here through a successful enrolment. Presence in the environment
 * configuration alone is not enough to be trusted.
 */
final class PeerRegistry
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REVOKED = 'revoked';

    public function __construct(
        private readonly StoragePaths $paths,
        private readonly array $configuredPeers = [],
        private readonly bool $allowRekey = false,
    ) {}

    public function all(): array
    {
        $peers = $this->read();

        foreach ($this->configuredPeers as $peerId => $peer) {
            if (! isset($peers[$peerId])) {
                $peers[$peerId] = [
                    'id' => $peerId,
                    'name' => (string) ($peer['name'] ?? $peerId),
                    'url' => (string) ($peer['url'] ?? ''),
                    'region' => (string) ($peer['region'] ?? ''),
                    'public_key' => null,
                    'fingerprint' => null,
                    'status' => self::STATUS_PENDING,
                    'enrolled_at' => null,
                    'approved_at' => null,
                    'revoked_at' => null,
                ];
            }
        }

        ksort($peers);

        return $peers;
    }

    public function get(string $peerId): ?array
    {
        return $this->all()[$peerId] ?? null;
    }

    public function isTrusted(string $peerId): bool
    {
        $peer = $this->get($peerId);

        return $peer !== null
            && $peer['status'] === self::STATUS_APPROVED
            && is_string($peer['public_key'])
            && $peer['public_key'] !== '';
    }

    public function publicKeyFor(string $peerId): ?string
    {
        if (! $this->isTrusted($peerId)) {
            return null;
        }

        return $this->get($peerId)['public_key'];
    }

    public function fingerprintFor(string $peerId): ?string
    {
        $peer = $this->get($peerId);

        return $peer === null ? null : $peer['fingerprint'];
    }

    /**
     * Pins a public key for a peer. The first enrolment wins unless the peer
     * is explicitly re-enrolled, which is what stops a stolen registration
     * request from silently swapping a trusted key.
     */
    public function approve(string $peerId, string $publicKey, string $url = '', string $name = '', string $region = ''): array
    {
        if (base64_decode($publicKey, true) === false || strlen((string) base64_decode($publicKey, true)) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new UnauthorizedNodeException("Public key for peer {$peerId} is not a valid Ed25519 key.");
        }

        $peers = $this->read();
        $existing = $peers[$peerId] ?? null;

        if (
            $existing !== null
            && $existing['status'] === self::STATUS_APPROVED
            && $existing['public_key'] !== $publicKey
            && ! $this->allowRekey
        ) {
            throw new UnauthorizedNodeException(
                "Peer {$peerId} is already approved with a different public key. Revoke it first to re-enrol."
            );
        }

        $peers[$peerId] = [
            'id' => $peerId,
            'name' => $name !== '' ? $name : (string) ($existing['name'] ?? $peerId),
            'url' => $url !== '' ? $url : (string) ($existing['url'] ?? ''),
            'region' => $region !== '' ? $region : (string) ($existing['region'] ?? ''),
            'public_key' => $publicKey,
            'fingerprint' => hash('sha256', $publicKey),
            'status' => self::STATUS_APPROVED,
            'enrolled_at' => (string) ($existing['enrolled_at'] ?? Timestamp::now()),
            'approved_at' => Timestamp::now(),
            'revoked_at' => null,
        ];

        $this->write($peers);

        return $peers[$peerId];
    }

    public function revoke(string $peerId): bool
    {
        $peers = $this->read();

        if (! isset($peers[$peerId])) {
            return false;
        }

        $peers[$peerId]['status'] = self::STATUS_REVOKED;
        $peers[$peerId]['revoked_at'] = Timestamp::now();

        $this->write($peers);

        return true;
    }

    public function forget(string $peerId): bool
    {
        $peers = $this->read();

        if (! isset($peers[$peerId])) {
            return false;
        }

        unset($peers[$peerId]);

        $this->write($peers);

        return true;
    }

    public function trusted(): array
    {
        return array_filter($this->all(), fn (array $peer): bool => $peer['status'] === self::STATUS_APPROVED);
    }

    private function read(): array
    {
        $path = $this->paths->peerRegistryFile();

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function write(array $peers): void
    {
        ksort($peers);

        AtomicFile::write($this->paths->peerRegistryFile(), CanonicalJson::encode($peers));
    }
}
