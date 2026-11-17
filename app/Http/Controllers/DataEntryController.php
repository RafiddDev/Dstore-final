<?php

namespace App\Http\Controllers;

use App\DStore\Network\PeerDirectory;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Synchronization\SyncEngine;
use App\DStore\Synchronization\SyncHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DataEntryController extends Controller
{
    public function index()
    {
        $nodes = $this->nodes();
        $current = $this->currentNode();

        return view('data-entry', [
            'nodes' => array_values($nodes),
            'current' => $current,
        ]);
    }

    public function store(Request $request, StorageEngineInterface $engine)
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'collection' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->createRecord($data, $engine);
    }

    // Fetch records from any node through the Pakistan server.
    public function nodeRecords(
        string $node,
        StorageEngineInterface $engine
    ): JsonResponse {

        if ($node === $this->currentNode()['id']) {
            return $this->records($engine);
        }

        $url = $this->nodeUrl($node);

        if (!$url) {
            return response()->json([
                'ok' => false,
                'error' => 'Unknown node: ' . $node,
            ], 404);
        }

        try {
            $response = Http::timeout(12)
                ->acceptJson()
                ->get($url . '/data-entry/records');

            $body = $response->json();

            if (!is_array($body)) {
                $body = [
                    'ok' => false,
                    'error' => 'Invalid response from node.',
                ];
            }

            return response()->json($body, $response->status());

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'Cannot connect to ' . $node,
            ], 502);
        }
    }

    // Save a record on the selected node.
    public function storeOnNode(
        Request $request,
        string $node,
        StorageEngineInterface $engine
    ): JsonResponse {

        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'collection' => ['nullable', 'string', 'max:64'],
        ]);

        // Save directly if the selected node is Pakistan.
        if ($node === $this->currentNode()['id']) {
            return $this->createRecord($data, $engine);
        }

        $url = $this->nodeUrl($node);

        if (!$url) {
            return response()->json([
                'ok' => false,
                'error' => 'Unknown node: ' . $node,
            ], 404);
        }

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->asJson()
                ->post($url . '/data-entry', $data);

            $body = $response->json();

            if (!is_array($body)) {
                $body = [
                    'ok' => false,
                    'error' => 'Invalid response from target node.',
                ];
            }

            return response()->json($body, $response->status());

        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'Unable to save on ' . $node,
            ], 502);
        }
    }

    private function createRecord(
        array $data,
        StorageEngineInterface $engine
    ): JsonResponse {

        $collection = ($data['collection'] ?? null) ?: 'accounts';
        $recordId = 'acc_' . Str::lower(Str::random(10));

        $record = $engine->create($collection, [
            'email' => $data['email'],
            'username' => $data['username'],
            'password' => $data['password'],
            'created_via' => $this->currentNode()['id'],
        ], $recordId);

        return response()->json([
            'ok' => true,
            'record' => [
                'id' => $record->recordId,
                'collection' => $record->collection,
                'data' => $record->data,
                'version' => $record->version(),
                'writer' => $record->writer(),
            ],
        ]);
    }

    public function records(
        StorageEngineInterface $engine
    ): JsonResponse {

        $items = [];

        foreach (['accounts', 'users'] as $collection) {
            foreach ($engine->all($collection) as $record) {
                $items[] = [
                    'id' => $record->recordId,
                    'collection' => $record->collection,
                    'data' => $record->data,
                    'version' => $record->version(),
                    'writer' => $record->writer(),
                    'updated_at' => $record->updatedAt(),
                    'created_at' => $record->createdAt(),
                ];
            }
        }

        usort($items, fn ($a, $b) =>
            strcmp(
                (string) ($b['updated_at'] ?? ''),
                (string) ($a['updated_at'] ?? '')
            )
        );

        return response()->json([
            'ok' => true,
            'node' => $this->currentNode(),
            'records' => $items,
        ]);
    }

    public function options(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    public function sync(SyncEngine $sync, PeerDirectory $directory): JsonResponse
    {
        $started = microtime(true);
        $peers = $directory->all();
        $reports = [];
        $reachable = 0;
        $pulled = 0;
        $applied = 0;
        $pushed = 0;
        $delivered = 0;
        $pending = 0;

        foreach (array_keys($peers) as $id) {
            $report = $sync->sync((string) $id);
            $reports[] = [
                'peer' => $report->peer,
                'reachable' => $report->reachable,
                'pulled' => $report->pulled,
                'applied' => $report->applied,
                'pushed' => $report->pushed,
                'delivered' => $report->delivered,
                'pending' => $report->pending,
                'error' => $report->error,
            ];

            if ($report->reachable) {
                $reachable++;
                $pulled += $report->pulled;
                $applied += $report->applied;
                $pushed += $report->pushed;
                $delivered += $report->delivered;
            }

            $pending += $report->pending;
        }

        return response()->json([
            'ok' => true,
            'node' => $this->currentNode(),
            'peers' => count($peers),
            'reachable' => $reachable,
            'pulled' => $pulled,
            'applied' => $applied,
            'pushed' => $pushed,
            'delivered' => $delivered,
            'pending' => $pending,
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
            'reports' => $reports,
        ]);
    }

    public function syncHistory(SyncHistory $history): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'node' => $this->currentNode(),
            'history' => $history->recent(80),
        ]);
    }

    public function nodeSyncHistory(string $node, SyncHistory $history): JsonResponse
    {
        if ($node === $this->currentNode()['id']) {
            return $this->syncHistory($history);
        }

        $url = $this->nodeUrl($node);

        if (! $url) {
            return response()->json([
                'ok' => false,
                'error' => 'Unknown node: '.$node,
            ], 404);
        }

        try {
            $response = Http::timeout(12)
                ->acceptJson()
                ->get($url.'/data-entry/sync-history');

            $body = $response->json();

            if (! is_array($body)) {
                $body = ['ok' => false, 'error' => 'Invalid response from node.'];
            }

            return response()->json($body, $response->status());
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'Cannot connect to '.$node,
            ], 502);
        }
    }

    public function syncOnNode(string $node, SyncEngine $sync, PeerDirectory $directory): JsonResponse
    {
        if ($node === $this->currentNode()['id']) {
            return $this->sync($sync, $directory);
        }

        $url = $this->nodeUrl($node);

        if (! $url) {
            return response()->json([
                'ok' => false,
                'error' => 'Unknown node: '.$node,
            ], 404);
        }

        // Same-host local nodes: avoid HTTP deadlock with PHP's single-threaded dev server.
        if ($this->isLocalNodeUrl($url)) {
            return $this->syncLocalNodeViaCli($node);
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->asJson()
                ->post($url.'/data-entry/sync', []);

            $body = $response->json();

            if (! is_array($body)) {
                $body = ['ok' => false, 'error' => 'Invalid response from node.'];
            }

            return response()->json($body, $response->status());
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'Cannot connect to '.$node,
            ], 502);
        }
    }

    private function isLocalNodeUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
    }

    private function syncLocalNodeViaCli(string $node): JsonResponse
    {
        $started = microtime(true);
        $php = PHP_BINARY ?: 'php';
        $artisan = base_path('artisan');
        $command = escapeshellarg($php).' '.escapeshellarg($artisan).' --env='.escapeshellarg($node).' dstore:sync:run 2>&1';
        $output = [];
        $code = 0;
        @exec($command, $output, $code);
        $durationMs = round((microtime(true) - $started) * 1000, 2);

        if ($code !== 0) {
            return response()->json([
                'ok' => false,
                'node' => ['id' => $node, 'name' => Str::headline($node), 'url' => (string) ($this->nodes()[$node]['url'] ?? '')],
                'duration_ms' => $durationMs,
                'error' => trim(implode("\n", $output)) ?: 'Sync command failed.',
            ], 502);
        }

        $history = [];

        try {
            $history = app(\App\DStore\Synchronization\SyncHistory::class, ['paths' => $this->pathsForNode($node)])->recent(10);
        } catch (\Throwable) {
            $history = [];
        }

        return response()->json([
            'ok' => true,
            'node' => ['id' => $node, 'name' => Str::headline($node), 'url' => (string) ($this->nodes()[$node]['url'] ?? '')],
            'duration_ms' => $durationMs,
            'peers' => null,
            'reachable' => null,
            'pulled' => null,
            'applied' => null,
            'pushed' => null,
            'delivered' => null,
            'pending' => null,
            'via' => 'cli',
            'output' => implode("\n", $output),
            'history' => $history,
        ]);
    }

    private function pathsForNode(string $node): \App\DStore\Storage\StoragePaths
    {
        // Build storage paths for target node's env (same machine, different node root).
        $baseRoot = base_path('nodes/'.$node.'/storage/dstore');
        $resolved = $baseRoot;

        try {
            $resolved = \App\DStore\Storage\StoragePaths::make($baseRoot, base_path())->root();
        } catch (\Throwable) {
        }

        return new \App\DStore\Storage\StoragePaths($resolved);
    }

    private function nodeUrl(string $nodeId): ?string
    {
        $nodes = $this->nodes();

        if (!isset($nodes[$nodeId])) {
            return null;
        }

        $url = trim((string) ($nodes[$nodeId]['url'] ?? ''));

        return $url !== '' ? rtrim($url, '/') : null;
    }

    private function nodes(): array
    {
        $nodes = [];
        $self = $this->currentNode();

        $nodes[$self['id']] = $self;

        foreach ((array) config('dstore.peers', []) as $id => $peer) {
            $nodes[$id] = [
                'id' => (string) ($peer['id'] ?? $id),
                'name' => Str::headline((string) ($peer['id'] ?? $id)),
                'url' => (string) ($peer['url'] ?? ''),
            ];
        }

        foreach ([
            'pakistan' => 'http://127.0.0.1:8001',
            // 'uae' => 'http://127.0.0.1:8002',
            // 'usa' => 'http://127.0.0.1:8003',
            'uk' => 'http://127.0.0.1:8004',
        ] as $id => $url) {

            if (!isset($nodes[$id])) {
                $nodes[$id] = [
                    'id' => $id,
                    'name' => Str::headline($id),
                    'url' => $url,
                ];
            } elseif (($nodes[$id]['url'] ?? '') === '') {
                $nodes[$id]['url'] = $url;
            }
        }

        foreach (['uae', 'usa'] as $disabled) {
            unset($nodes[$disabled]);
        }

        return $nodes;
    }

    private function currentNode(): array
    {
        $id = (string) config('dstore.node.id', 'node');

        return [
            'id' => $id,
            'name' => (string) config(
                'dstore.node.name',
                Str::headline($id)
            ),
            'url' => (string) config('dstore.node.public_url', ''),
        ];
    }

}