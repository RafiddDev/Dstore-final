<?php

namespace App\DStore\Network;

/**
 * The outcome of a single call to a peer.
 *
 * A failed call is a value, not an exception, because the replication engine
 * has to distinguish "the peer said no" from "the peer never answered".
 */
final class PeerResponse
{
    public function __construct(
        public readonly bool $successful,
        public readonly int $status,
        public readonly array $body,
        public readonly ?string $error = null,
        public readonly int $attempts = 1,
        public readonly float $durationMs = 0.0,
    ) {}

    public static function success(int $status, array $body, int $attempts, float $durationMs): self
    {
        return new self(true, $status, $body, null, $attempts, $durationMs);
    }

    public static function failure(int $status, array $body, string $error, int $attempts, float $durationMs): self
    {
        return new self(false, $status, $body, $error, $attempts, $durationMs);
    }

    public function unreachable(): bool
    {
        return $this->status === 0;
    }

    public function body(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }
}
