<?php

namespace App\DStore\Replication;

final class ReplicationResult
{
    public function __construct(
        public readonly string $peer,
        public readonly int $pushed,
        public readonly int $delivered,
        public readonly int $pending,
        public readonly array $appliedVector,
        public readonly bool $successful,
        public readonly string $error = '',
    ) {}

    public static function nothingToPush(string $peer, int $pending = 0): self
    {
        return new self($peer, 0, 0, $pending, [], true);
    }

    public static function pushed(string $peer, int $pushed, int $delivered, int $pending, array $appliedVector): self
    {
        return new self($peer, $pushed, $delivered, $pending, $appliedVector, true);
    }

    public static function failed(string $peer, int $pushed, int $pending, string $error): self
    {
        return new self($peer, $pushed, 0, $pending, [], false, $error);
    }
}
