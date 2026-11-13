<?php

namespace App\DStore\Recovery;

use App\DStore\Storage\RecordStore;
use App\DStore\Storage\StoragePaths;

/**
 * Removes tombstones (and the deleted record files they guard) once they are
 * older than the configured retention.
 *
 * Tombstones are cheap but not free: one file per deleted record. Keeping
 * them forever would turn a long-lived node into a graveyard. The retention
 * window is deliberately long enough that a node which was offline for a
 * normal outage still learns about the deletion when it returns.
 */
final class TombstoneGarbageCollector
{
    public function __construct(
        private readonly StoragePaths $paths,
        private readonly RecordStore $records,
        private readonly IndexRebuilder $indexes,
    ) {}

    /**
     * @return array{dry_run: bool, scanned: int, eligible: int, removed: int, records_removed: int, collections: list<string>}
     */
    public function collect(?int $days = null, bool $dryRun = false): array
    {
        $days = max(0, $days ?? 7);
        $cutoff = time() - ($days * 86400);
        $directory = $this->paths->tombstones();

        $scanned = 0;
        $eligible = [];

        if (is_dir($directory)) {
            foreach (scandir($directory) ?: [] as $file) {
                if (! str_ends_with($file, '.json')) {
                    continue;
                }

                $scanned++;
                $path = $directory.'/'.$file;
                $decoded = json_decode((string) file_get_contents($path), true);

                if (! is_array($decoded)) {
                    continue;
                }

                $deletedAt = strtotime((string) ($decoded['deleted_at'] ?? ''));

                if ($deletedAt === false || $deletedAt > $cutoff) {
                    continue;
                }

                $eligible[] = ['path' => $path, 'data' => $decoded];
            }
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'scanned' => $scanned,
                'eligible' => count($eligible),
                'removed' => 0,
                'records_removed' => 0,
                'collections' => [],
            ];
        }

        $removed = 0;
        $recordsRemoved = 0;
        $collections = [];

        foreach ($eligible as $entry) {
            $data = $entry['data'];
            $recordId = (string) ($data['record_id'] ?? basename((string) $entry['path'], '.json'));
            $collection = (string) ($data['collection'] ?? '');

            if ($collection !== '' && $recordId !== '') {
                $document = $this->records->read($collection, $recordId);

                if ($document !== null && $document->isDeleted()) {
                    $path = $this->records->path($collection, $recordId);

                    if (is_file($path)) {
                        @unlink($path);
                        $recordsRemoved++;
                    }
                }

                $collections[$collection] = true;
            }

            if (@unlink((string) $entry['path'])) {
                $removed++;
            }
        }

        $rebuilt = array_keys($collections);
        sort($rebuilt);

        foreach ($rebuilt as $collection) {
            $this->indexes->rebuild($collection);
        }

        return [
            'dry_run' => false,
            'scanned' => $scanned,
            'eligible' => count($eligible),
            'removed' => $removed,
            'records_removed' => $recordsRemoved,
            'collections' => $rebuilt,
        ];
    }
}
