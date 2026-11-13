<?php

use App\Http\Controllers\DStore\V1\EventController;
use App\Http\Controllers\DStore\V1\HealthController;
use App\Http\Controllers\DStore\V1\HeartbeatController;
use App\Http\Controllers\DStore\V1\NodeController;
use App\Http\Controllers\DStore\V1\RecordController;
use App\Http\Controllers\DStore\V1\SnapshotController;
use App\Http\Controllers\DStore\V1\SyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('dstore/v1')->group(function () {
    // The liveness probe stays open so a peer can be found before it is
    // trusted. It exposes nothing but the node id and the health of the
    // storage engine.
    Route::get('/health', HealthController::class);

    Route::middleware('dstore.signature')->group(function () {
        Route::get('/nodes', [NodeController::class, 'index']);
        Route::post('/nodes/status', [NodeController::class, 'status']);
        Route::post('/heartbeat', HeartbeatController::class);
        Route::post('/events/push', [EventController::class, 'push']);
        Route::get('/events', [EventController::class, 'index']);
        Route::get('/sync/status', SyncController::class);
        Route::get('/snapshots', [SnapshotController::class, 'index']);
        Route::get('/snapshots/{name}', [SnapshotController::class, 'show']);
        Route::post('/records/reconcile', [RecordController::class, 'reconcile']);

        // The enrolment door is reached before the two nodes trust each
        // other, so it authenticates with the bootstrap token and a proof of
        // possession instead of the signature middleware.
        Route::post('/nodes/register', [NodeController::class, 'register'])
            ->withoutMiddleware('dstore.signature');
    });
});
