<?php

namespace App\DStore\Storage;

use App\DStore\Support\CanonicalJson;

final class RecordDocument
{
    public function __construct(
        public readonly string $recordId,
        public readonly string $collection,
        public readonly array $meta,
        public readonly array $data,
    ) {}

    public static function make(string $collection, string $recordId, array $data, array $version, string $timestamp): self
    {
        return new self($recordId, $collection, [
            'record_id' => $recordId,
            'collection' => $collection,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'deleted' => false,
            'version' => $version,
            'history' => [],
        ], $data);
    }

    public static function fromArray(array $document): self
    {
        $meta = (array) ($document['meta'] ?? []);

        return new self(
            (string) ($meta['record_id'] ?? ''),
            (string) ($meta['collection'] ?? ''),
            $meta,
            (array) ($document['data'] ?? []),
        );
    }

    /**
     * Rebuilds the document an event describes.
     *
     * Every event carries the full state of the record at the moment it was
     * produced, so a record can always be restored from the log alone.
     */
    public static function fromEvent(Event $event): self
    {
        $meta = (array) ($event->payload['meta'] ?? []);
        $data = (array) ($event->payload['data'] ?? []);

        $meta['record_id'] = $event->recordId;
        $meta['collection'] = $event->collection;
        $meta['version'] = $event->originClock;
        $meta['deleted'] = (bool) ($meta['deleted'] ?? false);
        $meta['created_at'] = (string) ($meta['created_at'] ?? $event->createdAt);
        $meta['updated_at'] = (string) ($meta['updated_at'] ?? $event->createdAt);
        $meta['writer'] = $event->origin;

        return new self($event->recordId, $event->collection, $meta, $data);
    }

    public function version(): array
    {
        return (array) ($this->meta['version'] ?? []);
    }

    /**
     * The node that wrote the current state of this record.
     *
     * Used only as a deterministic tiebreak between two concurrent writes;
     * records written before the writer was tracked fall back to the
     * lexicographically greatest node in the version vector.
     */
    public function writer(): string
    {
        $writer = (string) ($this->meta['writer'] ?? '');

        if ($writer !== '') {
            return $writer;
        }

        $nodes = array_keys(array_filter($this->version(), fn ($counter): bool => (int) $counter > 0));

        if ($nodes === []) {
            return '';
        }

        sort($nodes);

        return (string) end($nodes);
    }

    public function isDeleted(): bool
    {
        return (bool) ($this->meta['deleted'] ?? false);
    }

    public function createdAt(): string
    {
        return (string) ($this->meta['created_at'] ?? '');
    }

    public function updatedAt(): string
    {
        return (string) ($this->meta['updated_at'] ?? '');
    }

    public function history(): array
    {
        return (array) ($this->meta['history'] ?? []);
    }

    public function withData(array $data, array $version, string $timestamp): self
    {
        $meta = $this->meta;
        $meta['history'] = $this->nextHistory();
        $meta['version'] = $version;
        $meta['updated_at'] = $timestamp;

        return new self($this->recordId, $this->collection, $meta, $data);
    }

    public function withDeleted(bool $deleted, array $version, string $timestamp): self
    {
        $meta = $this->meta;
        $meta['history'] = $this->nextHistory();
        $meta['version'] = $version;
        $meta['updated_at'] = $timestamp;
        $meta['deleted'] = $deleted;

        return new self($this->recordId, $this->collection, $meta, $this->data);
    }

    public function withWriter(string $writer): self
    {
        $meta = $this->meta;
        $meta['writer'] = $writer;

        return new self($this->recordId, $this->collection, $meta, $this->data);
    }

    public function withHistory(array $history): self
    {
        $meta = $this->meta;
        $meta['history'] = array_values($history);

        return new self($this->recordId, $this->collection, $meta, $this->data);
    }

    /**
     * Appends history entries that are not recorded yet.
     *
     * An event already carries the states the record went through on the
     * node that produced it, so a node that applies the event must not
     * record the same state a second time.
     */
    public static function mergeHistory(array $history, array $additional): array
    {
        $merged = array_values($history);
        $seen = [];

        foreach ($merged as $entry) {
            $seen[CanonicalJson::encode((array) ($entry['version'] ?? []))] = true;
        }

        foreach ($additional as $entry) {
            $key = CanonicalJson::encode((array) ($entry['version'] ?? []));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $entry;
        }

        return $merged;
    }

    /**
     * The union of two histories.
     *
     * The result does not depend on the order of the arguments: a state both
     * sides recorded is kept once and the entries are ordered by their
     * version, so two nodes that hold the same two histories build the same
     * list.
     */
    public static function unionHistory(array $a, array $b): array
    {
        $byVersion = [];

        foreach (array_merge(array_values($a), array_values($b)) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $key = CanonicalJson::encode((array) ($entry['version'] ?? []));
            $json = CanonicalJson::encode($entry);

            if (! isset($byVersion[$key]) || $json > $byVersion[$key]['json']) {
                $byVersion[$key] = ['json' => $json, 'entry' => $entry];
            }
        }

        ksort($byVersion);

        return array_values(array_map(fn (array $item): array => $item['entry'], $byVersion));
    }

    public function toArray(): array
    {
        return [
            'meta' => $this->meta,
            'data' => $this->data,
        ];
    }

    private function nextHistory(): array
    {
        $history = $this->history();

        $history[] = [
            'version' => $this->version(),
            'updated_at' => $this->updatedAt(),
            'deleted' => $this->isDeleted(),
        ];

        $limit = (int) config('dstore.consistency.history_limit', 50);

        if ($limit > 0 && count($history) > $limit) {
            $history = array_slice($history, -$limit);
        }

        return array_values($history);
    }
}
