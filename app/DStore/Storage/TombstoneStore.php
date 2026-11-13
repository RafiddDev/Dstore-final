<?php

namespace App\DStore\Storage;

use App\DStore\Support\CanonicalJson;
use App\DStore\Support\Timestamp;

/**
 * Tombstones mark records as deleted.
 *
 * They stop a stale create from resurrecting a record that was deleted while
 * this node was offline.
 */
final class TombstoneStore
{
    public function __construct(private readonly StoragePaths $paths) {}

    public function write(RecordDocument $document, array $version, string $nodeId): void
    {
        AtomicFile::write(
            $this->paths->tombstone($document->recordId),
            CanonicalJson::encode([
                'record_id' => $document->recordId,
                'collection' => $document->collection,
                'deleted_at' => $document->updatedAt() !== '' ? $document->updatedAt() : Timestamp::now(),
                'version' => $version,
                'node_id' => $nodeId,
            ]),
        );
    }

    public function has(string $recordId): bool
    {
        return is_file($this->paths->tombstone($recordId));
    }

    public function read(string $recordId): ?array
    {
        $path = $this->paths->tombstone($recordId);

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
