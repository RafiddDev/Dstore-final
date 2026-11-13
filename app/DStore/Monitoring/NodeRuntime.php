<?php

namespace App\DStore\Monitoring;

use App\DStore\Storage\AtomicFile;
use App\DStore\Storage\StoragePaths;
use App\DStore\Support\CanonicalJson;
use App\DStore\Support\Timestamp;

/**
 * The identity of the running node process ("this boot").
 *
 * The epoch is deliberately independent of the encryption keys: rotating a
 * key means the node changed identity, while restarting a process only means
 * it is the same node running again. Peers compare the epoch they saw last
 * time with the epoch reported by the next heartbeat to tell a restart from a
 * plain reconnect, and then run a catch-up sync.
 *
 * The long-running `dstore:monitor` (or `dstore:sync:daemon`) is the process
 * that represents the node's runtime: it calls {@see beginRun()} at start-up.
 * A per-request worker must never call it, or every request would look like a
 * restart.
 */
final class NodeRuntime
{
    private ?array $cached = null;

    public function __construct(private readonly StoragePaths $paths) {}

    /**
     * The current run, created on first use.
     *
     * @return array{epoch: string, started_at: string}
     */
    public function current(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $path = $this->paths->runtimeFile();

        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);

            if (is_array($decoded) && isset($decoded['epoch'], $decoded['started_at'])) {
                return $this->cached = [
                    'epoch' => (string) $decoded['epoch'],
                    'started_at' => (string) $decoded['started_at'],
                ];
            }
        }

        return $this->cached = $this->write($this->newEpoch(), Timestamp::now());
    }

    /**
     * Starts a new run. Only a long-lived node process may call this.
     *
     * @return array{epoch: string, started_at: string}
     */
    public function beginRun(): array
    {
        return $this->cached = $this->write($this->newEpoch(), Timestamp::now());
    }

    public function epoch(): string
    {
        return $this->current()['epoch'];
    }

    public function startedAt(): string
    {
        return $this->current()['started_at'];
    }

    public function uptimeSeconds(?int $now = null): int
    {
        $started = strtotime($this->startedAt());

        if ($started === false) {
            return 0;
        }

        return max(0, ($now ?? time()) - $started);
    }

    /**
     * @return array{epoch: string, started_at: string}
     */
    private function write(string $epoch, string $startedAt): array
    {
        AtomicFile::write(
            $this->paths->runtimeFile(),
            CanonicalJson::encode(['epoch' => $epoch, 'started_at' => $startedAt]),
        );

        return ['epoch' => $epoch, 'started_at' => $startedAt];
    }

    private function newEpoch(): string
    {
        return bin2hex(random_bytes(16));
    }
}
