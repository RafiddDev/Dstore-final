<?php

namespace App\DStore\Synchronization;

use App\DStore\Storage\AtomicFile;
use App\DStore\Storage\StoragePaths;
use App\DStore\Support\CanonicalJson;
use App\DStore\Support\Timestamp;

/**
 * Persists a bounded history of sync runs so operators can see when data was
 * synchronised and how long each run took.
 */
final class SyncHistory
{
    private const FILE = 'sync_history.json';
    private const LIMIT = 100;

    public function __construct(private readonly StoragePaths $paths) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $items = $this->all();

        return array_slice(array_reverse($items), 0, max(1, $limit));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function record(array $entry): void
    {
        $items = $this->all();
        $items[] = $entry;

        if (count($items) > self::LIMIT) {
            $items = array_slice($items, -self::LIMIT);
        }

        AtomicFile::write($this->path(), CanonicalJson::encode($items));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function log(string $peer, string $type, float $durationMs, array $report): void
    {
        $this->record([
            'at' => Timestamp::now(),
            'peer' => $peer,
            'type' => $type,
            'duration_ms' => round($durationMs, 2),
            'reachable' => (bool) ($report['reachable'] ?? false),
            'pulled' => (int) ($report['pulled'] ?? 0),
            'applied' => (int) ($report['applied'] ?? 0),
            'pushed' => (int) ($report['pushed'] ?? 0),
            'delivered' => (int) ($report['delivered'] ?? 0),
            'pending' => (int) ($report['pending'] ?? 0),
            'error' => (string) ($report['error'] ?? ''),
        ]);
    }

    private function path(): string
    {
        return $this->paths->metadata().'/'.self::FILE;
    }
}