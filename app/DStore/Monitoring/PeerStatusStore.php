<?php

namespace App\DStore\Monitoring;

use App\DStore\Storage\AtomicFile;
use App\DStore\Storage\StoragePaths;
use App\DStore\Support\CanonicalJson;
use App\DStore\Support\Timestamp;

/**
 * The last known reachability and reported health of every peer, persisted so
 * the failure detector and the dashboard do not have to wait for a fresh
 * probe.
 *
 * Two timestamps are kept apart on purpose: `last_seen_at` is this node's
 * clock (it can be trusted, it is ours) while `reported_at` is whatever the
 * peer said, and is only ever used as information, never for ordering.
 */
final class PeerStatusStore
{
    public const STATE_UNKNOWN = 'unknown';

    public const STATE_ONLINE = 'online';

    public const STATE_UNREACHABLE = 'unreachable';

    public const STATE_OFFLINE = 'offline';

    public const STATE_DEGRADED = 'degraded';

    public const STATE_DESTROYED = 'destroyed';

    public function __construct(private readonly StoragePaths $paths) {}

    public function all(): array
    {
        $path = $this->paths->peerStatusFile();

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function get(string $peerId): array
    {
        return $this->all()[$peerId] ?? self::defaults($peerId);
    }

    public function state(string $peerId): string
    {
        return (string) $this->get($peerId)['state'];
    }

    public function recordSuccess(string $peerId, float $responseMs, string $state = self::STATE_ONLINE, array $meta = []): array
    {
        return $this->recordHeartbeat($peerId, $responseMs, $state, $meta)['status'];
    }

    /**
     * Records a successful heartbeat, including the peer's reported health.
     *
     * @return array{status: array, restarted: bool}
     */
    public function recordHeartbeat(string $peerId, float $responseMs, string $state = self::STATE_ONLINE, array $meta = []): array
    {
        $current = $this->get($peerId);
        $reportedEpoch = (string) ($meta['epoch'] ?? '');
        $restarted = false;

        $previousEpoch = (string) ($current['epoch'] ?? '');

        if ($reportedEpoch !== '' && $previousEpoch !== '' && $reportedEpoch !== $previousEpoch) {
            $restarted = true;
        }

        $status = $this->write($peerId, array_merge(
            self::reportedFields($meta),
            [
                'state' => $state,
                'last_seen_at' => Timestamp::now(),
                'last_response_ms' => $responseMs,
                'last_error' => null,
                'consecutive_failures' => 0,
                'restarts' => (int) ($current['restarts'] ?? 0) + ($restarted ? 1 : 0),
                'last_restart_at' => $restarted ? Timestamp::now() : ($current['last_restart_at'] ?? null),
            ],
        ));

        return ['status' => $status, 'restarted' => $restarted];
    }

    public function recordFailure(string $peerId, string $error): array
    {
        $current = $this->get($peerId);

        return $this->write($peerId, [
            'state' => self::STATE_UNREACHABLE,
            'last_seen_at' => $current['last_seen_at'],
            'last_response_ms' => $current['last_response_ms'],
            'last_error' => $error,
            'consecutive_failures' => ((int) ($current['consecutive_failures'] ?? 0)) + 1,
        ]);
    }

    public function recordState(string $peerId, string $state): array
    {
        $current = $this->get($peerId);

        return $this->write($peerId, [
            'state' => $state,
            'last_seen_at' => $current['last_seen_at'],
            'last_response_ms' => $current['last_response_ms'],
            'last_error' => $current['last_error'],
            'consecutive_failures' => (int) ($current['consecutive_failures'] ?? 0),
        ]);
    }

    /**
     * A peer told us what it thinks of another peer.
     *
     * A direct observation always wins: gossip only fills in a peer this node
     * has never reached itself. That keeps a malicious or mistaken peer from
     * marking a healthy node offline, and it keeps the dashboard honest.
     */
    public function recordGossip(string $peerId, string $state, array $meta = []): array
    {
        $current = $this->get($peerId);

        if ((string) ($current['destroyed_at'] ?? '') !== '' || $current['last_seen_at'] !== null) {
            return $current;
        }

        return $this->write($peerId, array_merge(
            self::reportedFields($meta),
            ['state' => $state],
        ));
    }

    /**
     * Marks a peer as permanently destroyed. This is an administrative
     * decision, never inferred from missed heartbeats: a node that is merely
     * offline must always be able to rejoin.
     */
    public function markDestroyed(string $peerId): array
    {
        return $this->write($peerId, [
            'state' => self::STATE_DESTROYED,
            'destroyed_at' => Timestamp::now(),
        ]);
    }

    public function clearDestroyed(string $peerId): array
    {
        $current = $this->get($peerId);

        return $this->write($peerId, [
            'state' => self::STATE_UNKNOWN,
            'destroyed_at' => null,
            'last_error' => $current['last_error'],
        ]);
    }

    public function isDestroyed(string $peerId): bool
    {
        return (string) ($this->get($peerId)['destroyed_at'] ?? '') !== '';
    }

    private function write(string $peerId, array $changes): array
    {
        $statuses = $this->all();
        $current = $statuses[$peerId] ?? [];

        $statuses[$peerId] = array_merge(
            self::defaults($peerId),
            $current,
            $changes,
            ['updated_at' => Timestamp::now()],
        );

        ksort($statuses);

        AtomicFile::write($this->paths->peerStatusFile(), CanonicalJson::encode($statuses));

        return $statuses[$peerId];
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaults(string $peerId): array
    {
        return [
            'peer' => $peerId,
            'state' => self::STATE_UNKNOWN,
            'last_seen_at' => null,
            'last_response_ms' => null,
            'last_error' => null,
            'consecutive_failures' => 0,
            'updated_at' => null,
            'name' => '',
            'region' => '',
            'url' => '',
            'epoch' => '',
            'started_at' => null,
            'reported_at' => null,
            'restarts' => 0,
            'last_restart_at' => null,
            'records' => null,
            'events_committed' => null,
            'storage_bytes' => null,
            'degraded' => false,
            'destroyed_at' => null,
        ];
    }

    /**
     * Only the fields a peer is allowed to tell us about itself.
     *
     * @return array<string, mixed>
     */
    private static function reportedFields(array $meta): array
    {
        $allowed = [
            'name' => fn ($value): string => (string) $value,
            'region' => fn ($value): string => (string) $value,
            'url' => fn ($value): string => (string) $value,
            'epoch' => fn ($value): string => (string) $value,
            'started_at' => fn ($value): ?string => $value !== null && $value !== '' ? (string) $value : null,
            'reported_at' => fn ($value): ?string => $value !== null && $value !== '' ? (string) $value : null,
            'records' => fn ($value): ?int => is_numeric($value) ? (int) $value : null,
            'events_committed' => fn ($value): ?int => is_numeric($value) ? (int) $value : null,
            'storage_bytes' => fn ($value): ?int => is_numeric($value) ? (int) $value : null,
            'degraded' => fn ($value): bool => (bool) $value,
        ];

        $fields = [];

        foreach ($allowed as $key => $cast) {
            if (array_key_exists($key, $meta)) {
                $fields[$key] = $cast($meta[$key]);
            }
        }

        return $fields;
    }
}
