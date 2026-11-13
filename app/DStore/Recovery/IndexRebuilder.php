<?php

namespace App\DStore\Recovery;

use App\DStore\Storage\IndexStore;
use App\DStore\Storage\RecordStore;
use App\DStore\Storage\StoragePaths;

/**
 * Rebuilds the derived indexes from the record files, which are the source
 * of truth for the storage engine.
 *
 * An index can be lost, truncated or left behind by a crash; the records are
 * still on disk and still authoritative, so rebuilding is always safe.
 */
final class IndexRebuilder
{
    public function __construct(
        private readonly StoragePaths $paths,
        private readonly RecordStore $records,
        private readonly IndexStore $index,
    ) {}

    /**
     * @return array{collections: array<string, int>, records: int}
     */
    public function rebuildAll(): array
    {
        $collections = [];

        foreach ($this->collections() as $collection) {
            $collections[$collection] = $this->rebuild($collection);
        }

        return [
            'collections' => $collections,
            'records' => array_sum($collections),
        ];
    }

    public function rebuild(string $collection): int
    {
        $entries = [];

        foreach ($this->records->scan($collection) as $document) {
            $entries[] = $this->records->indexEntry($document);
        }

        return $this->index->rebuild($collection, $entries);
    }

    /**
     * @return list<string>
     */
    public function collections(): array
    {
        $directory = $this->paths->root().'/records';

        if (! is_dir($directory)) {
            return [];
        }

        $collections = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_dir($directory.'/'.$entry)) {
                continue;
            }

            $collections[] = $entry;
        }

        sort($collections);

        return $collections;
    }
}
