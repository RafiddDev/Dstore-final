<?php

namespace App\DStore\Recovery;

use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\RecordStore;
use App\DStore\Storage\IndexStore;
use App\DStore\Storage\SnapshotStore;
use App\DStore\Storage\TombstoneStore;
use App\DStore\Storage\RecordDocument;
use App\DStore\Support\VersionVector;

/**
 * Restores the records of a verified snapshot without ever replacing a newer
 * local version.
 *
 * A snapshot is an old point in time by definition. It is therefore only a
 * bootstrap for missing data: a record that already exists is kept unless
 * the snapshot's version strictly dominates it.
 */
final class SnapshotRestorer
{
    public function __construct(
        private readonly SnapshotStore $snapshots,
        private readonly RecordStore $records,
        private readonly IndexStore $index,
        private readonly TombstoneStore $tombstones,
    ) {}

    /**
     * @return array{snapshot: string, checked: int, restored: int, skipped: int}
     */
    public function restore(string $name): array
    {
        if (! $this->snapshots->verify($name)) {
            throw new \RuntimeException("Snapshot {$name} is missing or does not match its manifest.");
        }

        $payload = $this->snapshots->read($name);

        if ($payload === null) {
            throw new \RuntimeException("Snapshot {$name} could not be decoded.");
        }

        $checked = 0;
        $restored = 0;
        $skipped = 0;

        foreach ((array) ($payload['records'] ?? []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $checked++;
            $incoming = RecordDocument::fromArray($raw);

            if ($incoming->recordId === '' || $incoming->collection === '') {
                continue;
            }

            $local = $this->records->read($incoming->collection, $incoming->recordId);

            if ($local !== null && ! VersionVector::dominates($incoming->version(), $local->version())) {
                $skipped++;

                continue;
            }

            $stored = $this->records->write($incoming);

            $this->index->put(
                $stored->collection,
                $stored->recordId,
                $this->records->indexEntry($stored),
            );

            if ($stored->isDeleted()) {
                $this->tombstones->write($stored, $stored->version(), $stored->writer());
            }

            $restored++;
        }

        return [
            'snapshot' => (string) ($payload['name'] ?? $name),
            'checked' => $checked,
            'restored' => $restored,
            'skipped' => $skipped,
        ];
    }
}
