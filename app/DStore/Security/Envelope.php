<?php

namespace App\DStore\Security;

/**
 * The wire format for every node-to-node message.
 *
 * The signature covers everything except the signature field itself, so a
 * peer cannot swap the payload, the origin or the timestamp.
 */
final class Envelope
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $originNode,
        public readonly int $timestamp,
        public readonly string $nonce,
        public readonly array $payload,
        public readonly ?string $signature = null,
        public readonly ?string $keyId = null,
    ) {}

    public function signableContent(): array
    {
        return [
            'message_id' => $this->messageId,
            'origin_node' => $this->originNode,
            'timestamp' => $this->timestamp,
            'nonce' => $this->nonce,
            'payload' => $this->payload,
        ];
    }

    public function withSignature(string $signature, string $keyId): self
    {
        return new self(
            $this->messageId,
            $this->originNode,
            $this->timestamp,
            $this->nonce,
            $this->payload,
            $signature,
            $keyId,
        );
    }

    public function toArray(): array
    {
        return array_merge($this->signableContent(), [
            'signature' => $this->signature,
            'key_id' => $this->keyId,
        ]);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['message_id'] ?? ''),
            (string) ($data['origin_node'] ?? ''),
            (int) ($data['timestamp'] ?? 0),
            (string) ($data['nonce'] ?? ''),
            (array) ($data['payload'] ?? []),
            isset($data['signature']) ? (string) $data['signature'] : null,
            isset($data['key_id']) ? (string) $data['key_id'] : null,
        );
    }
}
