<?php

namespace App\DStore\Synchronization;

final class ApplyOutcome
{
    public const APPLIED = 'applied';

    public const DUPLICATE = 'duplicate';

    public const STALE = 'stale';

    public const CONFLICT = 'conflict';

    public const REJECTED = 'rejected';

    public function __construct(
        public readonly string $status,
        public readonly string $eventId,
        public readonly string $recordId,
        public readonly string $reason = '',
    ) {}

    public static function applied(string $eventId, string $recordId, string $reason = ''): self
    {
        return new self(self::APPLIED, $eventId, $recordId, $reason);
    }

    public static function duplicate(string $eventId, string $recordId): self
    {
        return new self(self::DUPLICATE, $eventId, $recordId, 'event was already applied');
    }

    public static function stale(string $eventId, string $recordId): self
    {
        return new self(self::STALE, $eventId, $recordId, 'local version is newer');
    }

    public static function conflict(string $eventId, string $recordId, string $reason): self
    {
        return new self(self::CONFLICT, $eventId, $recordId, $reason);
    }

    public static function rejected(string $eventId, string $recordId, string $reason): self
    {
        return new self(self::REJECTED, $eventId, $recordId, $reason);
    }

    public function isRejected(): bool
    {
        return $this->status === self::REJECTED;
    }
}
