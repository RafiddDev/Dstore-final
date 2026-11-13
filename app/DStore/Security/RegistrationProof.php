<?php

namespace App\DStore\Security;

use App\DStore\Support\CanonicalJson;

/**
 * The proof-of-possession block used when a node enrols with a peer.
 *
 * Signing the presented public key proves the sender holds the matching
 * private key, so a registration request cannot be replayed with somebody
 * else's key.
 */
final class RegistrationProof
{
    public static function content(array $data): array
    {
        return [
            'action' => 'register',
            'node_id' => (string) ($data['node_id'] ?? ''),
            'public_key' => (string) ($data['public_key'] ?? ''),
            'url' => (string) ($data['url'] ?? ''),
            'nonce' => (string) ($data['nonce'] ?? ''),
        ];
    }

    public static function encode(array $data): string
    {
        return CanonicalJson::encode(self::content($data));
    }
}
