<?php

namespace App\Http\Controllers;

use App\DStore\Storage\Contracts\StorageEngineInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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