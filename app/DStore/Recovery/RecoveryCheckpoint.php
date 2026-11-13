<?php

namespace App\DStore\Recovery;

use App\DStore\Storage\AtomicFile;
use App\DStore\Storage\StoragePaths;
use App\DStore\Support\CanonicalJson;
use App\DStore\Support\Timestamp;

/**
 * The durable record of what recovery did.
 *
 * `checkpoint.json` holds the last run so a restarted recovery command can
 * tell whether it already finished, and `checkpoints.jsonl` keeps the recent
 * history for the dashboard and for debugging.
 */
final class RecoveryCheckpoint
{
    public function __construct(private readonly StoragePaths $paths) {}

    public function current(): ?array
    {
        $path = $this->paths->recoveryCheckpoint();

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function start(string $reason = 'manual'): array
    {
        return $this->write([
            'status' => 'running',
            'reason' => $reason,
            'started_at' => Timestamp::now(),
            'finished_at' => null,
            'steps' => [],
            'error' => null,
        ], false);
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     * @return array<string, mixed>
     */
    public function finish(array $checkpoint, array $steps, string $status = 'completed', string $error = ''): array
    {
        $checkpoint['status'] = $status;
        $checkpoint['finished_at'] = Timestamp::now();
        $checkpoint['steps'] = $steps;
        $checkpoint['error'] = $error !== '' ? $error : null;

        return $this->write($checkpoint);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = 20): array
    {
        $path = $this->paths->recoveryHistory();

        if (! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -max(1, $limit));

        $entries = [];

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    public function clear(): void
    {
        $path = $this->paths->recoveryCheckpoint();

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     * @return array<string, mixed>
     */
    private function write(array $checkpoint, bool $appendHistory = true): array
    {
        AtomicFile::write(
            $this->paths->recoveryCheckpoint(),
            CanonicalJson::encode($checkpoint),
        );

        if (! $appendHistory) {
            return $checkpoint;
        }

        AtomicFile::append(
            $this->paths->recoveryHistory(),
            CanonicalJson::encode([
                'status' => $checkpoint['status'] ?? 'unknown',
                'reason' => $checkpoint['reason'] ?? 'manual',
                'started_at' => $checkpoint['started_at'] ?? null,
                'finished_at' => $checkpoint['finished_at'] ?? null,
                'error' => $checkpoint['error'] ?? null,
            ])."\n",
        );

        return $checkpoint;
    }
}
