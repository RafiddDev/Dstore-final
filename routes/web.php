<?php

use App\Http\Controllers\DataEntryController;
use App\Http\Controllers\DStore\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/nodes/{node}', [DashboardController::class, 'node'])->name('dashboard.node');
Route::get('/dashboard/data', [DashboardController::class, 'data'])->name('dashboard.data');
Route::get('/dashboard/data/nodes/{node}', [DashboardController::class, 'nodeData'])->name('dashboard.node.data');
Route::post('/dashboard/probe/{node}', [DashboardController::class, 'probe'])->name('dashboard.probe');

Route::get('/data-entry', [DataEntryController::class, 'index'])->name('data-entry');
Route::post('/data-entry', [DataEntryController::class, 'store'])->name('data-entry.store');
Route::get('/data-entry/records', [DataEntryController::class, 'records'])->name('data-entry.records');
Route::post('/data-entry/sync', [DataEntryController::class, 'sync'])->name('data-entry.sync');
Route::get('/data-entry/sync-history', [DataEntryController::class, 'syncHistory'])->name('data-entry.sync-history');
Route::post('/data-entry/add-node', [DataEntryController::class, 'addNode'])->name('data-entry.add-node');
Route::options('/data-entry', [DataEntryController::class, 'options']);
Route::options('/data-entry/records', [DataEntryController::class, 'options']);
Route::options('/data-entry/sync', [DataEntryController::class, 'options']);
Route::options('/data-entry/sync-history', [DataEntryController::class, 'options']);
Route::options('/data-entry/add-node', [DataEntryController::class, 'options']);
Route::get('/data-entry/nodes/{node}/records', [DataEntryController::class, 'nodeRecords']);
Route::get('/data-entry/nodes/{node}/sync-history', [DataEntryController::class, 'nodeSyncHistory']);
Route::post('/data-entry/nodes/{node}/sync', [DataEntryController::class, 'syncOnNode']);
Route::post('/data-entry/nodes/{node}', [DataEntryController::class, 'storeOnNode']);
Route::post('/data-entry/nodes/{node}/delete', [DataEntryController::class, 'deleteRecord']);
Route::options('/data-entry/nodes/{node}/records', [DataEntryController::class, 'options']);
Route::options('/data-entry/nodes/{node}/sync-history', [DataEntryController::class, 'options']);
Route::options('/data-entry/nodes/{node}/sync', [DataEntryController::class, 'options']);
Route::options('/data-entry/nodes/{node}', [DataEntryController::class, 'options']);

