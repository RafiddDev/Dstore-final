<?php

namespace App\DStore\Synchronization;

final class SyncReport
{
    public function __construct(
        public readonly string $peer,
        public readonly bool $reachable,
        public readonly int $pulled,
        public readonly int $applied,
        public readonly int $duplicates,
        public readonly int $rejected,
        public readonly int $pushed,
        public readonly int $delivered,
        public readonly int $pending,
        public readonly string $error = '',
    ) {}

    public function toArray(): array
    {
        return [
            'peer' => $this->peer,
            'reachable' => $this->reachable,
            'pulled' => $this->pulled,
            'applied' => $this->applied,
            'duplicates' => $this->duplicates,
            'rejected' => $this->rejected,
            'pushed' => $this->pushed,
            'delivered' => $this->delivered,
            'pending' => $this->pending,
            'error' => $this->error,
        ];
    }
}
