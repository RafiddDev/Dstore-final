<?php

namespace App\DStore\Monitoring;

/**
 * Turns the raw observation of a peer into the state the network acts on.
 *
 * Two rules matter most:
 *
 *  - a single missed heartbeat is suspicion, not death. The peer is
 *    `unreachable` until `misses_before_offline` consecutive failures have
 *    been recorded, and only then `offline`;
 *  - `destroyed` is never inferred. It is set by an administrator and stays
 *    set until an administrator clears it, because a node that is merely
 *    offline must always be allowed to rejoin.
 *
 * The detector is deliberately a pure decision function over the persisted
 * status. Persisting the resulting transition is done by {@see refresh()},
 * so the dashboard can show the same state the detector would compute.
 */
final class FailureDetector
{
    public function __construct(
        private readonly PeerStatusStore $statuses,
        private readonly int $heartbeatTimeout = 15,
        private readonly int $missesBeforeOffline = 3,
    ) {}

    /**
     * @return array{peer: string, state: string, previous: string, healthy: bool, silent_for: ?int, reason: string}
     */
    public function evaluate(string $peerId): array
    {
        return $this->evaluateStatus($this->statuses->get($peerId));
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array{peer: string, state: string, previous: string, healthy: bool, silent_for: ?int, reason: string}
     */
    public function evaluateStatus(array $status): array
    {
        $peerId = (string) ($status['peer'] ?? '');
        $previous = (string) ($status['state'] ?? PeerStatusStore::STATE_UNKNOWN);

        if ((string) ($status['destroyed_at'] ?? '') !== '') {
            return $this->decision($peerId, PeerStatusStore::STATE_DESTROYED, $previous, false, null, 'destroyed by an administrator');
        }

        $silentFor = $this->silentFor($status);
        $failures = (int) ($status['consecutive_failures'] ?? 0);

        if ($previous === PeerStatusStore::STATE_UNREACHABLE || $previous === 'failed') {
            if ($failures >= $this->missesBeforeOffline) {
                return $this->decision($peerId, PeerStatusStore::STATE_OFFLINE, $previous, false, $silentFor, "{$failures} consecutive misses");
            }

            return $this->decision($peerId, PeerStatusStore::STATE_UNREACHABLE, $previous, false, $silentFor, 'heartbeat failed');
        }

        if ($previous === PeerStatusStore::STATE_OFFLINE) {
            return $this->decision($peerId, PeerStatusStore::STATE_OFFLINE, $previous, false, $silentFor, "{$failures} consecutive misses");
        }

        if ($previous === PeerStatusStore::STATE_UNKNOWN) {
            return $this->decision($peerId, PeerStatusStore::STATE_UNKNOWN, $previous, false, $silentFor, 'no observation yet');
        }

        // Online or degraded: a stale observation is suspicion. It is never
        // promoted to offline on age alone, because age is also what an idle
        // but perfectly healthy peer looks like between two probes.
        if ($silentFor === null || $silentFor > $this->heartbeatTimeout) {
            $state = $failures >= $this->missesBeforeOffline
                ? PeerStatusStore::STATE_OFFLINE
                : PeerStatusStore::STATE_UNREACHABLE;

            return $this->decision($peerId, $state, $previous, false, $silentFor, 'heartbeat is overdue');
        }

        if ((bool) ($status['degraded'] ?? false)) {
            return $this->decision($peerId, PeerStatusStore::STATE_DEGRADED, $previous, false, $silentFor, 'peer reports storage corruption');
        }

        return $this->decision($peerId, PeerStatusStore::STATE_ONLINE, $previous, true, $silentFor, 'heartbeat is fresh');
    }

    /**
     * Persists every state transition the detector decides on.
     *
     * @param  iterable<string>|null  $peerIds  null means every known peer
     * @return array<string, array>
     */
    public function refresh(?iterable $peerIds = null): array
    {
        $ids = $peerIds;

        if ($ids === null) {
            $ids = array_keys($this->statuses->all());
        }

        $results = [];

        foreach ($ids as $peerId) {
            $evaluation = $this->evaluate((string) $peerId);
            $results[(string) $peerId] = $evaluation;

            if ($evaluation['state'] === $evaluation['previous']) {
                continue;
            }

            if ($evaluation['state'] === PeerStatusStore::STATE_DESTROYED) {
                continue;
            }

            if ($evaluation['state'] === PeerStatusStore::STATE_UNKNOWN) {
                continue;
            }

            $this->statuses->recordState((string) $peerId, $evaluation['state']);
        }

        return $results;
    }

    public function timeout(): int
    {
        return $this->heartbeatTimeout;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function silentFor(array $status): ?int
    {
        $lastSeen = (string) ($status['last_seen_at'] ?? '');

        if ($lastSeen === '') {
            return null;
        }

        $timestamp = strtotime($lastSeen);

        if ($timestamp === false) {
            return null;
        }

        return max(0, time() - $timestamp);
    }

    /**
     * @return array{peer: string, state: string, previous: string, healthy: bool, silent_for: ?int, reason: string}
     */
    private function decision(
        string $peerId,
        string $state,
        string $previous,
        bool $healthy,
        ?int $silentFor,
        string $reason,
    ): array {
        return [
            'peer' => $peerId,
            'state' => $state,
            'previous' => $previous,
            'healthy' => $healthy,
            'silent_for' => $silentFor,
            'reason' => $reason,
        ];
    }
}
