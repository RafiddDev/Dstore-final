<?php

namespace App\Http\Controllers\DStore\V1;

use App\DStore\Monitoring\NodeRuntime;
use App\DStore\Monitoring\PeerStatusStore;
use App\DStore\Security\Envelope;
use App\DStore\Security\IdentityRegistry;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\EventLog;
use App\DStore\Support\Timestamp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The heartbeat carries the sync metadata a peer needs to decide what to
 * push and what to pull, plus the health and epoch the monitor needs to tell
 * a restart from a reconnect.
 *
 * Receiving a valid signed heartbeat is also a direct observation of the
 * sender, so it updates the sender's status here as well.
 */
class HeartbeatController extends DStoreController
{
    public function __invoke(
        Request $request,
        StorageEngineInterface $engine,
        EventLog $events,
        NodeRuntime $runtime,
        IdentityRegistry $identities,
        PeerStatusStore $statuses,
    ): JsonResponse {
        $stats = $engine->stats();
        $identity = $identities->identity();
        $degraded = (bool) $stats['degraded'];

        $origin = $this->origin($request);
        $payload = $this->payload($request);

        if ($origin !== '' && $origin !== $identity->nodeId) {
            $statuses->recordHeartbeat(
                $origin,
                0.0,
                ($payload['degraded'] ?? false) ? PeerStatusStore::STATE_DEGRADED : PeerStatusStore::STATE_ONLINE,
                [
                    'name' => $payload['name'] ?? null,
                    'region' => $payload['region'] ?? null,
                    'url' => $payload['url'] ?? null,
                    'epoch' => $payload['epoch'] ?? null,
                    'started_at' => $payload['started_at'] ?? null,
                    'reported_at' => $payload['reported_at'] ?? Timestamp::now(),
                    'records' => $payload['records'] ?? null,
                    'events_committed' => $payload['events_committed'] ?? null,
                    'storage_bytes' => $payload['storage_bytes'] ?? null,
                    'degraded' => (bool) ($payload['degraded'] ?? false),
                ],
            );
        }

        return new JsonResponse([
            'ok' => true,
            'node_id' => $identity->nodeId,
            'name' => $identity->name,
            'region' => $identity->region,
            'url' => $identity->url,
            'status' => $degraded ? 'degraded' : 'healthy',
            'degraded' => $degraded,
            'epoch' => $runtime->epoch(),
            'started_at' => $runtime->startedAt(),
            'uptime_seconds' => $runtime->uptimeSeconds(),
            'applied_vector' => $events->appliedVector(),
            'events_committed' => $stats['events_committed'],
            'pending_total' => $stats['pending_total'],
            'pending' => $stats['pending'],
            'records' => $stats['total_records'],
            'storage_bytes' => $stats['storage_bytes'],
            'time' => Timestamp::now(),
        ]);
    }

    private function origin(Request $request): string
    {
        $envelope = $request->attributes->get('dstore_envelope');

        if ($envelope instanceof Envelope) {
            return $envelope->originNode;
        }

        if (is_array($envelope)) {
            return (string) ($envelope['origin_node'] ?? '');
        }

        return '';
    }
}
