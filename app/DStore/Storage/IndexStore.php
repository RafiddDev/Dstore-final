<?php

namespace App\DStore\Storage;

use App\DStore\Support\CanonicalJson;

final class IndexStore
{
    public function __construct(private readonly StoragePaths $paths) {}

    public function put(string $collection, string $recordId, array $entry): void
    {
        $payload = [
            'record_id' => $recordId,
            'path' => $entry['path'] ?? '',
            'size' => (int) ($entry['size'] ?? 0),
            'hash' => $entry['hash'] ?? '',
            'updated_at' => $entry['updated_at'] ?? '',
            'version' => $entry['version'] ?? [],
            'deleted' => (bool) ($entry['deleted'] ?? false),
        ];

        AtomicFile::append($this->paths->byIdIndex($collection), CanonicalJson::encode($payload)."\n");
        AtomicFile::append($this->paths->byUpdatedIndex($collection), CanonicalJson::encode([
            'record_id' => $recordId,
            'updated_at' => $payload['updated_at'],
            'version_sum' => array_sum(array_map('intval', (array) $payload['version'])),
            'deleted' => $payload['deleted'],
        ])."\n");

        $this->compactIfNeeded($collection);
    }

    public function get(string $collection, string $recordId): ?array
    {
        return $this->all($collection)[$recordId] ?? null;
    }

    public function all(string $collection): array
    {
        $entries = [];

        foreach ($this->lines($this->paths->byIdIndex($collection)) as $line) {
            $decoded = json_decode($line, true);

            if (! is_array($decoded) || ! isset($decoded['record_id'])) {
                continue;
            }

            $entries[(string) $decoded['record_id']] = $decoded;
        }

        return $entries;
    }

    public function byUpdated(string $collection): array
    {
        $entries = [];

        foreach ($this->lines($this->paths->byUpdatedIndex($collection)) as $line) {
            $decoded = json_decode($line, true);

            if (! is_array($decoded) || ! isset($decoded['record_id'])) {
                continue;
            }

            $entries[(string) $decoded['record_id']] = $decoded;
        }

        uasort($entries, function (array $a, array $b): int {
            return strcmp((string) $b['updated_at'], (string) $a['updated_at']);
        });

        return $entries;
    }

    public function forget(string $collection, string $recordId): void
    {
        $entries = $this->all($collection);
        unset($entries[$recordId]);

        $this->rewriteById($collection, $entries);
    }

    public function count(string $collection): int
    {
        return count($this->all($collection));
    }

    public function collections(): array
    {
        $directory = $this->paths->root().'/indexes';

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

    public function rebuild(string $collection, iterable $entries): int
    {
        $byId = [];
        $byUpdated = [];

        foreach ($entries as $entry) {
            $recordId = (string) ($entry['record_id'] ?? '');

            if ($recordId === '') {
                continue;
            }

            $byId[$recordId] = $entry;
            $byUpdated[$recordId] = [
                'record_id' => $recordId,
                'updated_at' => $entry['updated_at'] ?? '',
                'version_sum' => array_sum(array_map('intval', (array) ($entry['version'] ?? []))),
                'deleted' => (bool) ($entry['deleted'] ?? false),
            ];
        }

        $this->rewriteById($collection, $byId);
        $this->rewriteByUpdated($collection, $byUpdated);

        return count($byId);
    }

    private function rewriteById(string $collection, array $entries): void
    {
        $lines = [];

        foreach ($entries as $entry) {
            $lines[] = CanonicalJson::encode($entry);
        }

        AtomicFile::write(
            $this->paths->byIdIndex($collection),
            $lines === [] ? '' : implode("\n", $lines)."\n",
        );
    }

    private function rewriteByUpdated(string $collection, array $entries): void
    {
        $lines = [];

        foreach ($entries as $entry) {
            $lines[] = CanonicalJson::encode($entry);
        }

        AtomicFile::write(
            $this->paths->byUpdatedIndex($collection),
            $lines === [] ? '' : implode("\n", $lines)."\n",
        );
    }

    private function compactIfNeeded(string $collection): void
    {
        $path = $this->paths->byIdIndex($collection);

        if (! is_file($path)) {
            return;
        }

        $lineCount = count($this->lines($path));
        $liveCount = count($this->all($collection));

        if ($lineCount > ($liveCount * 2) + 50) {
            $this->rewriteById($collection, $this->all($collection));
            $this->rewriteByUpdated($collection, $this->byUpdated($collection));
        }
    }

    private function lines(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $lines = [];

        try {
            flock($handle, LOCK_SH);

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line !== '') {
                    $lines[] = $line;
                }
            }

            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return $lines;
    }
}
