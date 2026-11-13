<?php

namespace App\Http\Controllers\DStore\V1;

use App\DStore\Monitoring\NodeRuntime;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The liveness probe. It is intentionally reachable without a signature so a
 * peer can be discovered before it is trusted, and it therefore exposes
 * nothing but identity, uptime and the health of the storage engine.
 */
class HealthController extends Controller
{
    public function __invoke(StorageEngineInterface $engine, NodeRuntime $runtime): JsonResponse
    {
        $stats = $engine->stats();
        $degraded = (bool) $stats['degraded'];

        return new JsonResponse([
            'ok' => ! $degraded,
            'node_id' => $stats['node_id'],
            'status' => $degraded ? 'degraded' : 'healthy',
            'storage_ok' => ! $degraded,
            'degraded' => $degraded,
            'records' => $stats['total_records'],
            'events_applied' => $stats['events_applied'],
            'storage_bytes' => $stats['storage_bytes'],
            'epoch' => $runtime->epoch(),
            'started_at' => $runtime->startedAt(),
            'uptime_seconds' => $runtime->uptimeSeconds(),
            'time' => now()->toIso8601String(),
        ]);
    }
}
