<?php

namespace App\DStore\Storage;

use App\DStore\ConflictResolution\ConflictLog;
use App\DStore\Security\EventSigner;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\Exceptions\RecordAlreadyExistsException;
use App\DStore\Storage\Exceptions\RecordNotFoundException;
use App\DStore\Support\IdGenerator;
use App\DStore\Support\Timestamp;
use App\DStore\Support\VersionVector;

final class StorageEngine implements StorageEngineInterface
{
    public function __construct(
        private readonly StoragePaths $paths,
        private readonly RecordStore $records,
        private readonly EventLog $events,
        private readonly IndexStore $index,
        private readonly SnapshotStore $snapshots,
        private readonly IdGenerator $ids,
        private readonly FileLocker $locker,
        private readonly IntegrityGuard $integrity,
        private readonly HealthState $health,
        private readonly TombstoneStore $tombstones,
        private readonly EventSigner $signer,
        private readonly ConflictLog $conflicts,
        private readonly string $nodeId,
        private readonly array $peerIds,
    ) {}

    public function create(string $collection, array $data, ?string $recordId = null): RecordDocument
    {
        $recordId ??= $this->ids->generate($collection);

        return $this->locker->withExclusiveLock(
            "record:{$collection}:{$recordId}",
            function () use ($collection, $data, $recordId): RecordDocument {
                if ($this->records->exists($collection, $recordId)) {
                    throw new RecordAlreadyExistsException(
                        "Record {$recordId} already exists in collection {$collection}"
                    );
                }

                $version = VersionVector::increment([], $this->nodeId);
                $document = RecordDocument::make($collection, $recordId, $data, $version, Timestamp::now());

                return $this->commit($document, Event::TYPE_CREATED, $version);            }
        );
    }

    public function read(string $collection, string $recordId): ?RecordDocument
    {
        return $this->records->read($collection, $recordId);
    }

    public function readOrFail(string $collection, string $recordId): RecordDocument
    {
        $document = $this->records->read($collection, $recordId);

        if ($document === null) {
            throw new RecordNotFoundException("Record {$recordId} not found in collection {$collection}");
        }

        return $document;
    }

    public function update(string $collection, string $recordId, array $data): RecordDocument
    {
        return $this->locker->withExclusiveLock(
            "record:{$collection}:{$recordId}",
            function () use ($collection, $recordId, $data): RecordDocument {
                $current = $this->records->read($collection, $recordId);

                if ($current === null) {
                    throw new RecordNotFoundException(
                        "Record {$recordId} not found in collection {$collection}"
                    );
                }

                $version = VersionVector::increment($current->version(), $this->nodeId);
                $document = $current->withData(array_merge($current->data, $data), $version, Timestamp::now());

                return $this->commit($document, Event::TYPE_UPDATED, $version);
            }
        );
    }

    public function delete(string $collection, string $recordId): bool
    {
        return $this->locker->withExclusiveLock(
            "record:{$collection}:{$recordId}",
            function () use ($collection, $recordId): bool {
                $current = $this->records->read($collection, $recordId);

                if ($current === null) {
                    throw new RecordNotFoundException(
                        "Record {$recordId} not found in collection {$collection}"
                    );
                }

                if ($current->isDeleted()) {
                    return false;
                }

                $version = VersionVector::increment($current->version(), $this->nodeId);
                $document = $current->withDeleted(true, $version, Timestamp::now());

                $this->commit($document, Event::TYPE_DELETED, $version);
                $this->tombstones->write($document, $version, $this->nodeId);

                return true;
            }
        );
    }

    public function all(string $collection, bool $includeDeleted = false): array
    {
        $documents = [];

        foreach (array_keys($this->index->all($collection)) as $recordId) {
            $document = $this->records->read($collection, $recordId);

            if ($document === null) {
                continue;
            }

            if (! $includeDeleted && $document->isDeleted()) {
                continue;
            }

            $documents[] = $document;
        }

        return $documents;
    }

    public function count(string $collection): int
    {
        return count($this->all($collection));
    }

    public function history(string $collection, string $recordId): array
    {
        return $this->readOrFail($collection, $recordId)->history();
    }

    public function snapshot(string $collection): string
    {
        return $this->snapshots->create($collection, $this->nodeId);
    }

    public function verify(): array
    {
        return $this->integrity->verifyTree();
    }

    public function stats(): array
    {
        $collections = [];
        $total = 0;

        foreach ($this->index->collections() as $collection) {
            $count = count($this->all($collection));

            $collections[$collection] = $count;
            $total += $count;
        }

        $pending = [];

        foreach ($this->peerIds as $peer) {
            $pending[$peer] = $this->events->pendingCount($peer);
        }

        return [
            'node_id' => $this->nodeId,
            'collections' => $collections,
            'total_records' => $total,
            'events_applied' => $this->events->appliedCount(),
            'events_committed' => $this->committedEventCount(),
            'pending' => $pending,
            'pending_total' => array_sum($pending),
            'conflicts' => $this->conflicts->count(),
            'degraded' => $this->health->isDegraded(),
            'issues' => $this->health->issues(),
            'storage_bytes' => $this->storageBytes(),
        ];
    }

    private function commit(RecordDocument $document, string $type, array $version): RecordDocument
    {
        $document = $document->withWriter($this->nodeId);

        $seq = $this->events->nextSeq($this->nodeId);

        $event = Event::compose(
            $this->nodeId,
            $seq,
            $type,
            $document,
            $version,
            $document->updatedAt(),
            $this->events->lastEventId($this->nodeId),
        );

        $event = $this->signer->sign($event);

        $this->events->append($event);

        $stored = $this->records->write($document);

        $this->index->put(
            $document->collection,
            $document->recordId,
            $this->records->indexEntry($stored),
        );

        $this->events->markApplied($event->eventId);

        foreach ($this->peerIds as $peer) {
            $this->events->enqueuePending($peer, $event);
        }

        return $stored;
    }

    private function committedEventCount(): int
    {
        $count = 0;

        foreach ($this->events->all() as $event) {
            $count++;
        }

        return $count;
    }

    private function storageBytes(): int
    {
        $bytes = 0;
        $directory = $this->paths->root();

        if (! is_dir($directory)) {
            return 0;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
            }
        }

        return $bytes;
    }
}
