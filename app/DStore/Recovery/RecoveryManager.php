<?php

namespace App\DStore\Recovery;

use App\DStore\Network\PeerDirectory;
use App\DStore\Security\IdentityRegistry;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\SnapshotStore;
use App\DStore\Synchronization\SyncEngine;
use App\DStore\Support\Timestamp;

/**
 * Drives the rejoin sequence of a node that was offline, crashed or lost
 * part of its storage.
 *
 * The order mirrors the architecture document:
 *
 *  1. prove the identity material is intact;
 *  2. verify the record hashes (corruption is quarantined and the node stays
 *     degraded rather than serving bad data);
 *  3. optionally restore a snapshot, only for records that are missing or
 *     older;
 *  4. replay the committed event log through the normal apply rules;
 *  5. rebuild the derived indexes;
 *  6. resume normal synchronisation with every reachable peer;
 *  7. persist a checkpoint so the run is auditable and restartable.
 *
 * Nothing here bypasses the version vector: recovery cannot make an older
 * state win over a newer one.
 */
final class RecoveryManager
{
    public function __construct(
        private readonly IdentityRegistry $identities,
        private readonly StorageEngineInterface $engine,
        private readonly SnapshotStore $snapshots,
        private readonly SnapshotRestorer $snapshotRestorer,
        private readonly EventReplayer $replayer,
        private readonly IndexRebuilder $indexes,
        private readonly BackupManager $backups,
        private readonly RecoveryCheckpoint $checkpoints,
        private readonly SyncEngine $sync,
        private readonly PeerDirectory $peers,
        private readonly bool $autoSnapshotRestore = false,
        private readonly bool $backupBeforeRestore = false,
    ) {}

    /**
     * @param  array{reason?: string, snapshot?: ?string, restore_snapshot?: bool, sync?: bool, verify?: bool}  $options
     * @return array<string, mixed>
     */
    public function recover(array $options = []): array
    {
        $reason = (string) ($options['reason'] ?? 'manual');
        $checkpoint = $this->checkpoints->start($reason);
        $steps = [];

        try {
            $steps[] = $this->step('identity', function (): array {
                $identity = $this->identities->identity();

                return [
                    'ok' => $identity->nodeId !== '',
                    'node_id' => $identity->nodeId,
                    'fingerprint' => $identity->shortFingerprint(),
                ];
            });

            if (($options['verify'] ?? true) !== false) {
                $steps[] = $this->step('verify', function (): array {
                    $result = $this->engine->verify();

                    return [
                        'ok' => $result['corrupted'] === [],
                        'checked' => $result['checked'],
                        'corrupted' => count($result['corrupted']),
                        'degraded' => $result['degraded'],
                    ];
                });
            }

            $snapshot = $options['snapshot'] ?? null;
            $restoreSnapshot = (bool) ($options['restore_snapshot'] ?? false);
            $autoSnapshot = (bool) ($options['auto_snapshot_restore'] ?? $this->autoSnapshotRestore);

            if ($restoreSnapshot || ($snapshot !== null && $snapshot !== '') || $autoSnapshot) {
                $name = $snapshot !== null && $snapshot !== ''
                    ? (string) $snapshot
                    : $this->latestSnapshot();

                if ($name === null) {
                    $steps[] = ['step' => 'snapshot', 'ok' => true, 'skipped' => 'no snapshot available'];
                } else {
                    if ($this->backupBeforeRestore) {
                        $steps[] = $this->step('backup', fn (): array => $this->backups->create() + ['ok' => true]);
                    }

                    $steps[] = $this->step('snapshot', fn (): array => $this->snapshotRestorer->restore($name) + ['ok' => true]);
                }
            } else {
                $steps[] = ['step' => 'snapshot', 'ok' => true, 'skipped' => 'not requested'];
            }

            $steps[] = $this->step('replay', fn (): array => $this->replayer->replay() + ['ok' => true]);
            $steps[] = $this->step('indexes', fn (): array => $this->indexes->rebuildAll() + ['ok' => true]);

            if (($options['sync'] ?? true) !== false) {
                $steps[] = $this->step('sync', function (): array {
                    $reports = [];

                    foreach (array_keys($this->peers->all()) as $peerId) {
                        $reports[(string) $peerId] = $this->sync->sync((string) $peerId)->toArray();
                    }

                    return [
                        'ok' => true,
                        'peers' => count($reports),
                        'reports' => $reports,
                    ];
                });
            }

            $checkpoint = $this->checkpoints->finish($checkpoint, $steps);

            return [
                'status' => 'completed',
                'reason' => $reason,
                'steps' => $steps,
                'checkpoint' => $checkpoint,
                'finished_at' => Timestamp::now(),
            ];
        } catch (\Throwable $exception) {
            $this->checkpoints->finish($checkpoint, $steps, 'failed', $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * The newest snapshot available, or null when there is none.
     */
    private function latestSnapshot(): ?string
    {
        $snapshots = $this->snapshots->list();

        return $snapshots === [] ? null : (string) ($snapshots[0]['name'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $name, callable $callback): array
    {
        $started = microtime(true);

        $result = (array) $callback();

        return array_merge(['step' => $name], $result, [
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
        ]);
    }
}
