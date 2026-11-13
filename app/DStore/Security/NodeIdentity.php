<?php

namespace App\DStore\Security;

use App\DStore\Support\Timestamp;

/**
 * The public half of a node's identity. This object must never carry the
 * secret key: it is what gets shared with peers and rendered in the UI.
 */
final class NodeIdentity
{
    public function __construct(
        public readonly string $nodeId,
        public readonly string $name,
        public readonly string $region,
        public readonly string $publicKey,
        public readonly string $createdAt,
        public readonly string $url = '',
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', $this->publicKey);
    }

    public function shortFingerprint(): string
    {
        return substr($this->fingerprint(), 0, 16);
    }

    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'name' => $this->name,
            'region' => $this->region,
            'public_key' => $this->publicKey,
            'fingerprint' => $this->fingerprint(),
            'created_at' => $this->createdAt,
            'url' => $this->url,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['node_id'] ?? ''),
            (string) ($data['name'] ?? ''),
            (string) ($data['region'] ?? ''),
            (string) ($data['public_key'] ?? ''),
            (string) ($data['created_at'] ?? Timestamp::now()),
            (string) ($data['url'] ?? ''),
        );
    }
}
