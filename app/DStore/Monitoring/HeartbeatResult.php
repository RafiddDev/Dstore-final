<?php

namespace App\DStore\Monitoring;

/**
 * The outcome of one heartbeat to one peer.
 *
 * It is a value, not an exception, because the monitor has to keep probing
 * the rest of the mesh after one peer fails.
 */
final class HeartbeatResult
{
    public function __construct(
        public readonly string $peer,
        public readonly bool $reachable,
        public readonly string $state,
        public readonly array $appliedVector = [],
        public readonly string $epoch = '',
        public readonly bool $restarted = false,
        public readonly float $responseMs = 0.0,
        public readonly int $attempts = 1,
        public readonly string $error = '',
    ) {}

    public function toArray(): array
    {
        return [
            'peer' => $this->peer,
            'reachable' => $this->reachable,
            'state' => $this->state,
            'applied_vector' => $this->appliedVector,
            'epoch' => $this->epoch,
            'restarted' => $this->restarted,
            'response_ms' => $this->responseMs,
            'attempts' => $this->attempts,
            'error' => $this->error,
        ];
    }
}
