<?php

namespace App\Http\Controllers\DStore\V1;

use App\DStore\ConflictResolution\ConflictLog;
use App\DStore\ConflictResolution\RecordComparer;
use App\DStore\ConflictResolution\ResolverFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inspects what this node holds for a set of records and how it relates to
 * what a peer claims about the same records.
 */
class RecordController extends DStoreController
{
    public function reconcile(Request $request, RecordComparer $comparer, ConflictLog $conflicts): JsonResponse
    {
        $payload = $this->payload($request);

        $collection = (string) ($payload['collection'] ?? 'users');
        $recordIds = $payload['record_ids'] ?? [];
        $peerVersions = $payload['peer_versions'] ?? [];

        if (! is_array($recordIds) || $recordIds === []) {
            return new JsonResponse(
                ['ok' => false, 'error' => 'record_ids must be a non-empty array.'],
                422,
            );
        }

        if (! is_array($peerVersions)) {
            return new JsonResponse(
                ['ok' => false, 'error' => 'peer_versions must be an object.'],
                422,
            );
        }

        $ids = [];

        foreach ($recordIds as $recordId) {
            if (is_string($recordId) && $recordId !== '') {
                $ids[] = $recordId;
            }
        }

        if ($ids === []) {
            return new JsonResponse(
                ['ok' => false, 'error' => 'record_ids must contain record ids.'],
                422,
            );
        }

        $versions = [];

        foreach ($peerVersions as $recordId => $version) {
            if (is_string($recordId) && is_array($version)) {
                $versions[$recordId] = array_map('intval', $version);
            }
        }

        return new JsonResponse([
            'ok' => true,
            'policy' => (string) config('dstore.consistency.policy', ResolverFactory::POLICY_VECTOR_LWW),
            'policies' => ResolverFactory::policies(),
            'collection' => $collection,
            'records' => $comparer->compare($collection, $ids, $versions),
            'conflicts' => $conflicts->count(),
        ]);
    }
}
