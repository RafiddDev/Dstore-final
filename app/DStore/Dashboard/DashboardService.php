<?php

namespace App\DStore\Dashboard;

use App\DStore\ConflictResolution\ConflictLog;
use App\DStore\Monitoring\FailureDetector;
use App\DStore\Monitoring\NodeRuntime;
use App\DStore\Monitoring\PeerStatusStore;
use App\DStore\Network\PeerDirectory;
use App\DStore\Recovery\BackupManager;
use App\DStore\Recovery\RecoveryCheckpoint;
use App\DStore\Security\IdentityRegistry;
use App\DStore\Security\PeerRegistry;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\EventLog;
use App\DStore\Synchronization\DeliveryTracker;
use App\DStore\Support\Timestamp;
use Illuminate\Support\Str;

/**
 * Builds the read model the dashboard renders.
 *
 * Everything here comes from the node's own files or from the last signed
 * heartbeat it recorded; nothing is hard-coded. The dashboard is a view over
 * the same state the rest of the system acts on, which is why a node that
 * stops answering really does turn red.
 *
 * The service deliberately exposes no secret material: identity cards,
 * fingerprints and public health only. A peer's pinned public key is not
 * secret either, but the dashboard has no use for it and leaves it out.
 */
final class DashboardService
{
    public function __construct(
        private readonly IdentityRegistry $identities,
        private readonly NodeRuntime $runtime,
        private readonly StorageEngineInterface $engine,
        private readonly EventLog $events,
        private readonly PeerDirectory $directory,
        private readonly PeerRegistry $registry,
        private readonly PeerStatusStore $statuses,
        private readonly FailureDetector $detector,
        private readonly DeliveryTracker $cursors,
        private readonly ConflictLog $conflicts,
        private readonly BackupManager $backups,
        private readonly RecoveryCheckpoint $checkpoints,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $self = $this->self();
        $nodes = [$self];

        if (! $this->selfOnlyDashboard()) {
            foreach (array_keys($this->directory->all()) as $peerId) {
                $node = $this->peer((string) $peerId);

                if ($node !== null) {
                    $nodes[] = $node;
                }
            }
        }

        $summary = $this->summarise($nodes);
        $summary['records'] = $self['records'];
        $summary['events_committed'] = $self['events_committed'];
        $summary['pending_total'] = (int) $self['pending_total'];
        $summary['storage_bytes'] = $self['storage_bytes'];
        $summary['conflicts'] = $this->conflicts->count();

        return [
            'node' => $self,
            'summary' => $summary,
            'nodes' => $nodes,
            'conflicts' => $this->recentConflicts(),
            'backups' => array_slice($this->backups->list(), 0, 5),
            'recovery' => $this->checkpoints->current(),
            'recovery_history' => $this->checkpoints->history(5),
            'generated_at' => Timestamp::now(),
        ];
    }

    /**
     * One node by id: `self`/this node's id for the local node, otherwise a peer.
     *
     * @return array<string, mixed>|null
     */
    public function node(string $nodeId): ?array
    {
        if ($nodeId === $this->identity()->nodeId) {
            return $this->self();
        }

        if ($this->selfOnlyDashboard()) {
            return null;
        }

        return $this->peer($nodeId);
    }

    public function selfOnlyDashboard(): bool
    {
        return (bool) config('dstore.dashboard.self_only', false);
    }

    public function isSelf(string $nodeId): bool
    {
        return $nodeId === $this->identity()->nodeId;
    }

    /**
     * @return array<string, mixed>
     */
    public function self(): array
    {
        $identity = $this->identity();
        $stats = $this->engine->stats();
        $degraded = (bool) $stats['degraded'];
        $state = $degraded ? PeerStatusStore::STATE_DEGRADED : PeerStatusStore::STATE_ONLINE;

        return [
            'node_id' => $identity->nodeId,
            'name' => $identity->name,
            'region' => $identity->region,
            'url' => $identity->url,
            'role' => 'self',
            'trust' => 'self',
            'fingerprint' => $identity->shortFingerprint(),
            'state' => $state,
            'reachable' => true,
            'degraded' => $degraded,
            'destroyed' => false,
            'latency_ms' => null,
            'silent_for' => 0,
            'last_seen_at' => Timestamp::now(),
            'last_error' => null,
            'consecutive_failures' => 0,
            'epoch' => $this->runtime->epoch(),
            'started_at' => $this->runtime->startedAt(),
            'uptime_seconds' => $this->runtime->uptimeSeconds(),
            'records' => $stats['total_records'],
            'collections' => $stats['collections'],
            'events_committed' => $stats['events_committed'],
            'events_applied' => $stats['events_applied'],
            'pending' => $stats['pending'],
            'pending_total' => $stats['pending_total'],
            'conflicts' => $stats['conflicts'],
            'storage_bytes' => $stats['storage_bytes'],
            'issues' => $stats['issues'],
            'applied_vector' => $this->events->appliedVector(),
            'restarts' => 0,
            'last_restart_at' => null,
            'last_sync_at' => null,
            'reported_at' => Timestamp::now(),
            'sync_state' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function peer(string $peerId): ?array
    {
        $endpoint = $this->directory->find($peerId);
        $record = $this->registry->get($peerId);

        if ($endpoint === null && $record === null) {
            return null;
        }

        $status = $this->statuses->get($peerId);
        $evaluation = $this->detector->evaluate($peerId);
        $cursor = $this->cursors->cursor($peerId)->toArray();

        $name = (string) ($status['name'] ?? '');

        if ($name === '') {
            $name = (string) ($record['name'] ?? '');
        }

        if ($name === '') {
            $name = Str::headline($peerId);
        }

        $region = (string) ($status['region'] ?? '');

        if ($region === '') {
            $region = (string) ($record['region'] ?? '');
        }

        $url = (string) ($status['url'] ?? '');

        if ($url === '') {
            $url = (string) ($record['url'] ?? ($endpoint?->url ?? ''));
        }

        return [
            'node_id' => $peerId,
            'name' => $name,
            'region' => $region,
            'url' => $url,
            'role' => 'peer',
            'trust' => (string) ($record['status'] ?? PeerRegistry::STATUS_PENDING),
            'fingerprint' => $record['fingerprint'] ?? null,
            'state' => (string) $evaluation['state'],
            'reachable' => in_array($evaluation['state'], [
                PeerStatusStore::STATE_ONLINE,
                PeerStatusStore::STATE_DEGRADED,
            ], true),
            'degraded' => (string) $evaluation['state'] === PeerStatusStore::STATE_DEGRADED,
            'destroyed' => (string) $evaluation['state'] === PeerStatusStore::STATE_DESTROYED,
            'latency_ms' => $status['last_response_ms'],
            'silent_for' => $evaluation['silent_for'],
            'last_seen_at' => $status['last_seen_at'],
            'last_error' => $status['last_error'],
            'consecutive_failures' => (int) $status['consecutive_failures'],
            'epoch' => (string) ($status['epoch'] ?? ''),
            'started_at' => $status['started_at'] ?? null,
            'uptime_seconds' => $this->uptime($status['started_at'] ?? null),
            'records' => $status['records'],
            'collections' => null,
            'events_committed' => $status['events_committed'],
            'events_applied' => null,
            'pending' => null,
            'pending_total' => (int) ($cursor['pending_count'] ?? 0),
            'conflicts' => null,
            'storage_bytes' => $status['storage_bytes'],
            'issues' => [],
            'applied_vector' => (array) ($cursor['applied'] ?? []),
            'delivered_vector' => (array) ($cursor['delivered'] ?? []),
            'restarts' => (int) ($status['restarts'] ?? 0),
            'last_restart_at' => $status['last_restart_at'] ?? null,
            'last_sync_at' => $cursor['last_sync_at'] ?? null,
            'reported_at' => $status['reported_at'] ?? null,
            'sync_state' => $cursor['state'] ?? null,
            'sync_error' => $cursor['last_error'] ?? null,
            'reason' => $evaluation['reason'] ?? null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentConflicts(int $limit = 8): array
    {
        return array_slice(array_reverse($this->conflicts->all()), 0, max(1, $limit));
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, int|string>
     */
    private function summarise(array $nodes): array
    {
        $counts = [
            PeerStatusStore::STATE_ONLINE => 0,
            PeerStatusStore::STATE_DEGRADED => 0,
            PeerStatusStore::STATE_UNREACHABLE => 0,
            PeerStatusStore::STATE_OFFLINE => 0,
            PeerStatusStore::STATE_UNKNOWN => 0,
            PeerStatusStore::STATE_DESTROYED => 0,
        ];

        $trusted = 0;

        foreach ($nodes as $node) {
            $state = (string) ($node['state'] ?? PeerStatusStore::STATE_UNKNOWN);
            $counts[$state] = ($counts[$state] ?? 0) + 1;

            if (($node['trust'] ?? '') === PeerRegistry::STATUS_APPROVED || ($node['trust'] ?? '') === 'self') {
                $trusted++;
            }
        }

        $unhealthy = $counts[PeerStatusStore::STATE_DEGRADED]
            + $counts[PeerStatusStore::STATE_UNREACHABLE]
            + $counts[PeerStatusStore::STATE_OFFLINE]
            + $counts[PeerStatusStore::STATE_DESTROYED];

        $mesh = match (true) {
            count($nodes) <= 1 => 'standalone',
            $unhealthy > 0 => 'degraded',
            $counts[PeerStatusStore::STATE_UNKNOWN] > 0 => 'incomplete',
            default => 'healthy',
        };

        return [
            'total' => count($nodes),
            'online' => $counts[PeerStatusStore::STATE_ONLINE],
            'degraded' => $counts[PeerStatusStore::STATE_DEGRADED],
            'unreachable' => $counts[PeerStatusStore::STATE_UNREACHABLE],
            'offline' => $counts[PeerStatusStore::STATE_OFFLINE],
            'unknown' => $counts[PeerStatusStore::STATE_UNKNOWN],
            'destroyed' => $counts[PeerStatusStore::STATE_DESTROYED],
            'trusted' => $trusted,
            'mesh' => $mesh,
        ];
    }

    private function identity(): \App\DStore\Security\NodeIdentity
    {
        return $this->identities->identity();
    }

    private function uptime(?string $startedAt): ?int
    {
        if ($startedAt === null || $startedAt === '') {
            return null;
        }

        $timestamp = strtotime($startedAt);

        if ($timestamp === false) {
            return null;
        }

        return max(0, time() - $timestamp);
    }
}
