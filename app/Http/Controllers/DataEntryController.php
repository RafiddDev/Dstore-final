<?php

namespace App\Http\Controllers;

use App\DStore\Storage\Contracts\StorageEngineInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class DataEntryController extends Controller
{
    public function index(Request $request)
    {
        $nodes = $this->nodes();
        $current = $this->currentNode();

        return view('data-entry', [
            'nodes' => $nodes,
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

        $collection = $data['collection'] ?: 'accounts';
        $recordId = 'acc_' . Str::lower(Str::random(10));

        $record = $engine->create($collection, [
            'email' => $data['email'],
            'username' => $data['username'],
            'password' => $data['password'],
            'created_via' => $this->currentNode()['id'],
        ], $recordId);

        if ($request->expectsJson()) {
            return new JsonResponse([
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

        return redirect('/data-entry?saved=1&node=' . urlencode($this->currentNode()['id']) . '&id=' . urlencode($recordId));
    }

    public function records(StorageEngineInterface $engine): JsonResponse
    {
        $collections = ['accounts', 'users'];
        $items = [];

        foreach ($collections as $collection) {
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

        usort($items, fn ($a, $b) => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));

        return response()->json([
            'ok' => true,
            'node' => $this->currentNode(),
            'records' => $items,
        ])->header('Access-Control-Allow-Origin', '*')
          ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
          ->header('Access-Control-Allow-Headers', 'Content-Type, Accept');
    }

    public function options(): JsonResponse
    {
        return response()->json(['ok' => true])
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Accept');
    }

    /**
     * @return array<string, array{id:string,name:string,url:string}>
     */
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

        // Ensure stable known set for local 4-node setup
        foreach ([
            'pakistan' => 'http://127.0.0.1:8001',
            'uae' => 'http://127.0.0.1:8002',
            'usa' => 'http://127.0.0.1:8003',
            'uk' => 'http://127.0.0.1:8004',
        ] as $id => $url) {
            if (!isset($nodes[$id])) {
                $nodes[$id] = ['id' => $id, 'name' => Str::headline($id), 'url' => $url];
            } elseif (($nodes[$id]['url'] ?? '') === '') {
                $nodes[$id]['url'] = $url;
            }
        }

        return $nodes;
    }

    /**
     * @return array{id:string,name:string,url:string}
     */
    private function currentNode(): array
    {
        $id = (string) config('dstore.node.id', 'node');

        return [
            'id' => $id,
            'name' => (string) config('dstore.node.name', Str::headline($id)),
            'url' => (string) config('dstore.node.public_url', ''),
        ];
    }
}
