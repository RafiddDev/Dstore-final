<?php

namespace App\DStore\Storage;

use App\DStore\Storage\Exceptions\CorruptRecordException;
use App\DStore\Support\CanonicalJson;

final class RecordStore
{
    public function __construct(
        private readonly StoragePaths $paths,
        private readonly IntegrityGuard $integrity,
    ) {}

    public function path(string $collection, string $recordId): string
    {
        return $this->paths->record($collection, $recordId);
    }

    public function exists(string $collection, string $recordId): bool
    {
        return is_file($this->path($collection, $recordId));
    }

    public function read(string $collection, string $recordId): ?RecordDocument
    {
        $path = $this->path($collection, $recordId);

        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        $decoded = $raw === false ? null : json_decode($raw, true);

        if (! is_array($decoded)) {
            $this->integrity->quarantine($path, 'invalid json document');

            throw new CorruptRecordException("Record document is not valid JSON: {$path}");
        }

        if (! $this->integrity->verifyDocument($decoded)) {
            $this->integrity->quarantine($path, 'hash mismatch');

            throw new CorruptRecordException("Record document failed integrity verification: {$path}");
        }

        return RecordDocument::fromArray($decoded);
    }

    public function write(RecordDocument $document): RecordDocument
    {
        $meta = $document->meta;
        $meta['hash'] = $this->integrity->documentHash($meta, $document->data);

        $stored = new RecordDocument($document->recordId, $document->collection, $meta, $document->data);

        AtomicFile::write(
            $this->path($document->collection, $document->recordId),
            CanonicalJson::encode($stored->toArray()),
        );

        return $stored;
    }

    public function delete(string $collection, string $recordId): bool
    {
        $path = $this->path($collection, $recordId);

        if (! is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    public function scan(string $collection): iterable
    {
        $directory = $this->paths->records($collection);

        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $shard) {
            if ($shard === '.' || $shard === '..' || ! is_dir($directory.'/'.$shard)) {
                continue;
            }

            foreach (scandir($directory.'/'.$shard) ?: [] as $file) {
                if (! str_ends_with($file, '.json')) {
                    continue;
                }

                $recordId = substr($file, 0, -5);
                $document = $this->read($collection, $recordId);

                if ($document !== null) {
                    yield $document;
                }
            }
        }
    }

    public function count(string $collection): int
    {
        $count = 0;

        foreach ($this->scan($collection) as $ignored) {
            $count++;
        }

        return $count;
    }

    public function indexEntry(RecordDocument $document): array
    {
        $path = $this->path($document->collection, $document->recordId);

        return [
            'record_id' => $document->recordId,
            'path' => ltrim(str_replace($this->paths->root(), '', $path), '/'),
            'size' => is_file($path) ? (int) filesize($path) : 0,
            'hash' => (string) ($document->meta['hash'] ?? ''),
            'updated_at' => $document->updatedAt(),
            'version' => $document->version(),
            'deleted' => $document->isDeleted(),
        ];
    }
}
