<?php

namespace App\Http\Controllers\DStore\V1;

use App\DStore\Monitoring\FailureDetector;
use App\DStore\Monitoring\PeerStatusStore;
use App\DStore\Security\Exceptions\SecurityException;
use App\DStore\Security\IdentityRegistry;
use App\DStore\Security\NodeRegistrar;
use App\DStore\Security\PeerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NodeController extends DStoreController
{
    public function index(PeerRegistry $registry, PeerStatusStore $statuses, FailureDetector $detector): JsonResponse
    {
        $nodes = [];

        foreach ($registry->all() as $id => $peer) {
            $status = $statuses->get($id);
            $evaluation = $detector->evaluate($id);

            $nodes[] = [
                'node_id' => $id,
                'name' => $peer['name'],
                'region' => $peer['region'],
                'url' => $peer['url'],
                'status' => $peer['status'],
                'fingerprint' => $peer['fingerprint'],
                'state' => $evaluation['state'],
                'reachable' => in_array($evaluation['state'], [
                    PeerStatusStore::STATE_ONLINE,
                    PeerStatusStore::STATE_DEGRADED,
                ], true),
                'degraded' => $evaluation['state'] === PeerStatusStore::STATE_DEGRADED,
                'last_seen_at' => $status['last_seen_at'],
                'last_response_ms' => $status['last_response_ms'],
                'last_error' => $status['last_error'],
                'consecutive_failures' => $status['consecutive_failures'],
                'epoch' => $status['epoch'],
                'started_at' => $status['started_at'],
                'restarts' => $status['restarts'],
                'last_restart_at' => $status['last_restart_at'],
                'records' => $status['records'],
                'events_committed' => $status['events_committed'],
                'storage_bytes' => $status['storage_bytes'],
                'destroyed_at' => $status['destroyed_at'],
            ];
        }

        return new JsonResponse(['ok' => true, 'nodes' => $nodes]);
    }

    /**
     * The enrolment door: bootstrap token + proof of possession of the
     * presented public key.
     */
    public function register(Request $request, NodeRegistrar $registrar, IdentityRegistry $identities): JsonResponse
    {
        $nodeId = (string) $request->input('node_id', '');

        try {
            $peer = $registrar->register(
                $nodeId,
                (string) $request->input('public_key', ''),
                $request->input('token') !== null ? (string) $request->input('token') : null,
                (string) $request->input('url', ''),
                (string) $request->input('name', ''),
                (string) $request->input('region', ''),
                $request->input('nonce') !== null ? (string) $request->input('nonce') : null,
                $request->input('signature') !== null ? (string) $request->input('signature') : null,
            );
        } catch (SecurityException $exception) {
            return new JsonResponse(['ok' => false, 'error' => $exception->getMessage()], 403);
        }

        return new JsonResponse([
            'ok' => true,
            'peer' => $peer,
            'node' => $identities->publicIdentity(),
        ]);
    }

    /**
     * Gossip about the health of the peers we observed.
     *
     * Gossip fills gaps only. A peer we have observed ourselves is never
     * overridden by what a third node says about it.
     */
    public function status(Request $request, PeerStatusStore $statuses): JsonResponse
    {
        $observations = $this->payload($request)['observations'] ?? [];

        if (! is_array($observations)) {
            return new JsonResponse(['ok' => false, 'error' => 'observations must be an array.'], 422);
        }

        $recorded = 0;

        foreach ($observations as $observation) {
            if (! is_array($observation) || ! isset($observation['node_id'])) {
                continue;
            }

            $nodeId = (string) $observation['node_id'];

            if ($nodeId === (string) config('dstore.node.id')) {
                continue;
            }

            $statuses->recordGossip($nodeId, (string) ($observation['state'] ?? PeerStatusStore::STATE_UNKNOWN), [
                'epoch' => $observation['epoch'] ?? null,
                'reported_at' => $observation['last_seen_at'] ?? null,
            ]);

            $recorded++;
        }

        return new JsonResponse([
            'ok' => true,
            'recorded' => $recorded,
            'peers' => $statuses->all(),
        ]);
    }
}
