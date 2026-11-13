<?php

namespace App\DStore\Storage;

use App\DStore\Support\CanonicalJson;
use App\DStore\Support\Hash;

final class Event
{
    public const TYPE_CREATED = 'record.created';

    public const TYPE_UPDATED = 'record.updated';

    public const TYPE_DELETED = 'record.deleted';

    public function __construct(
        public readonly string $eventId,
        public readonly int $seq,
        public readonly string $origin,
        public readonly string $type,
        public readonly string $collection,
        public readonly string $recordId,
        public readonly array $payload,
        public readonly array $originClock,
        public readonly string $createdAt,
        public readonly ?string $prevEventId,
        public readonly ?string $signature = null,
    ) {}

    public static function compose(
        string $origin,
        int $seq,
        string $type,
        RecordDocument $document,
        array $originClock,
        string $createdAt,
        ?string $prevEventId,
    ): self {
        $payload = [
            'meta' => array_diff_key($document->meta, ['hash' => null]),
            'data' => $document->data,
        ];

        $content = [
            'seq' => $seq,
            'origin_node' => $origin,
            'type' => $type,
            'collection' => $document->collection,
            'record_id' => $document->recordId,
            'payload' => $payload,
            'origin_clock' => $originClock,
            'created_at' => $createdAt,
            'prev_event_id' => $prevEventId,
        ];

        $hash = Hash::sha256(CanonicalJson::encode($content));

        return new self(
            sprintf('%s-%08d-%s', $origin, $seq, substr($hash, 0, 8)),
            $seq,
            $origin,
            $type,
            $document->collection,
            $document->recordId,
            $payload,
            $originClock,
            $createdAt,
            $prevEventId,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['event_id'] ?? ''),
            (int) ($data['seq'] ?? 0),
            (string) ($data['origin_node'] ?? ''),
            (string) ($data['type'] ?? ''),
            (string) ($data['collection'] ?? ''),
            (string) ($data['record_id'] ?? ''),
            (array) ($data['payload'] ?? []),
            (array) ($data['origin_clock'] ?? []),
            (string) ($data['created_at'] ?? ''),
            isset($data['prev_event_id']) ? (string) $data['prev_event_id'] : null,
            isset($data['signature']) ? (string) $data['signature'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'seq' => $this->seq,
            'origin_node' => $this->origin,
            'type' => $this->type,
            'collection' => $this->collection,
            'record_id' => $this->recordId,
            'payload' => $this->payload,
            'origin_clock' => $this->originClock,
            'created_at' => $this->createdAt,
            'prev_event_id' => $this->prevEventId,
            'signature' => $this->signature,
        ];
    }

    /**
     * The exact content a signature covers. The signature field itself is
     * excluded, otherwise signing would be circular.
     */
    public function signableContent(): array
    {
        $content = $this->toArray();

        unset($content['signature']);

        return $content;
    }

    public function withSignature(string $signature): self
    {
        return new self(
            $this->eventId,
            $this->seq,
            $this->origin,
            $this->type,
            $this->collection,
            $this->recordId,
            $this->payload,
            $this->originClock,
            $this->createdAt,
            $this->prevEventId,
            $signature,
        );
    }

    public function contentHash(): string
    {
        return Hash::sha256(CanonicalJson::encode($this->signableContent()));
    }

    /**
     * The hash the event id is derived from.
     *
     * It covers everything except the id and the signature, so a payload
     * that was changed after the event was produced can never keep the
     * original id.
     */
    public function originHash(): string
    {
        $content = $this->toArray();

        unset($content['event_id'], $content['signature']);

        return Hash::sha256(CanonicalJson::encode($content));
    }

    /**
     * Whether the stored content still hashes to the stored id.
     *
     * This is what makes an unsigned event safe to re-sign: the content is
     * tamper-evident even before a signature exists.
     */
    public function idMatchesContent(): bool
    {
        if ($this->eventId === '') {
            return false;
        }

        return str_ends_with($this->eventId, '-'.substr($this->originHash(), 0, 8));
    }

    public function isSigned(): bool
    {
        return $this->signature !== null && $this->signature !== '';
    }
}
