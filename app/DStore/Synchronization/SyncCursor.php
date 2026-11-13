<?php

namespace App\DStore\Synchronization;

/**
 * What this node knows about one peer.
 *
 * `applied` is the vector the peer reported it has applied, `delivered` is
 * the vector of *our* events the peer has confirmed. The two are kept apart
 * because a peer can be ahead of us on somebody else's events while still
 * missing ours.
 */
final class SyncCursor
{
    public const STATE_UNKNOWN = 'unknown';

    public const STATE_IN_SYNC = 'in-sync';

    public const STATE_BEHIND = 'behind';

    public const STATE_AHEAD = 'ahead';

    public const STATE_UNREACHABLE = 'unreachable';

    public const STATE_FAILED = 'failed';

    public function __construct(
        public readonly string $peer,
        public readonly array $applied = [],
        public readonly array $delivered = [],
        public readonly int $pendingCount = 0,
        public readonly ?string $lastHeartbeatAt = null,
        public readonly ?string $lastSyncAt = null,
        public readonly string $state = self::STATE_UNKNOWN,
        public readonly int $attempts = 0,
        public readonly ?string $lastError = null,
        public readonly bool $failed = false,
    ) {}

    public static function empty(string $peer): self
    {
        return new self($peer);
    }

    public function toArray(): array
    {
        return [
            'peer' => $this->peer,
            'applied' => $this->applied,
            'delivered' => $this->delivered,
            'pending_count' => $this->pendingCount,
            'last_heartbeat_at' => $this->lastHeartbeatAt,
            'last_sync_at' => $this->lastSyncAt,
            'state' => $this->state,
            'attempts' => $this->attempts,
            'last_error' => $this->lastError,
            'failed' => $this->failed,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['peer'] ?? ''),
            (array) ($data['applied'] ?? []),
            (array) ($data['delivered'] ?? []),
            (int) ($data['pending_count'] ?? 0),
            isset($data['last_heartbeat_at']) ? (string) $data['last_heartbeat_at'] : null,
            isset($data['last_sync_at']) ? (string) $data['last_sync_at'] : null,
            (string) ($data['state'] ?? self::STATE_UNKNOWN),
            (int) ($data['attempts'] ?? 0),
            isset($data['last_error']) ? (string) $data['last_error'] : null,
            (bool) ($data['failed'] ?? false),
        );
    }
}
