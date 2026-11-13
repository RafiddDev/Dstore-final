<?php

namespace App\Http\Controllers\DStore\V1;

use App\DStore\Storage\SnapshotStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class SnapshotController extends Controller
{
    public function index(SnapshotStore $snapshots): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'snapshots' => $snapshots->list(),
        ]);
    }

    public function show(string $name, SnapshotStore $snapshots): JsonResponse
    {
        $snapshot = $snapshots->read($name);

        if ($snapshot === null) {
            return new JsonResponse(['ok' => false, 'error' => "Snapshot {$name} not found."], 404);
        }

        return new JsonResponse([
            'ok' => true,
            'snapshot' => $snapshot,
            'verified' => $snapshots->verify($name),
        ]);
    }
}
