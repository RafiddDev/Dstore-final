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
    public function index(Request $request)
    {
        $nodes = $this->nodes();
        $current = $this->currentNode();
        $setupToken = trim((string) $request->query('setup', ''));

        // Allow base URL to act as one-time setup URL if an unused token exists.
        if ($setupToken === '') {
            $stored = $this->readSetupToken();
            if (is_array($stored) && empty($stored['used_at']) && !empty($stored['token'])) {
                $setupToken = (string) $stored['token'];
            }
        }

        $setupMode = $setupToken !== '' && $this->isValidSetupToken($setupToken);

        return view('data-entry', [
            'nodes' => array_values($nodes),
            'current' => $current,
            'setupMode' => $setupMode,
            'setupToken' => $setupMode ? $setupToken : '',
        ]);
    }

    public function store(Request $request, StorageEngineInterface $engine)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
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
            'username' => ['required', 'string', 'max:255'],
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
            'username' => $data['username'],
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

    public function deleteRecord(
        Request $request,
        string $node,
        StorageEngineInterface $engine
    ): JsonResponse {
        $data = $request->validate([
            'collection' => ['required', 'string', 'max:64'],
            'record_id' => ['required', 'string', 'max:128'],
        ]);

        $collection = $data['collection'];
        $recordId = $data['record_id'];

        if ($node === $this->currentNode()['id']) {
            try {
                $ok = $engine->delete($collection, $recordId);
                return response()->json(['ok' => $ok, 'node' => $this->currentNode()['id'], 'collection' => $collection, 'record_id' => $recordId]);
            } catch (\Throwable $e) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 404);
            }
        }

        $url = $this->nodeUrl($node);
        if (!$url) {
            return response()->json(['ok' => false, 'error' => 'Unknown node: ' . $node], 404);
        }

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->asJson()
                ->post($url . '/data-entry/nodes/' . urlencode($node) . '/delete', [
                    'collection' => $collection,
                    'record_id' => $recordId,
                ]);
            $body = $response->json();
            if (!is_array($body)) {
                $body = ['ok' => false, 'error' => 'Invalid response from node.'];
            }
            return response()->json($body, $response->status());
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Cannot connect to ' . $node], 502);
        }
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

    public function addNode(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'id' => ['nullable', 'string', 'max:64', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/'],
                'name' => ['required', 'string', 'max:120'],
                'region' => ['nullable', 'string', 'max:120'],
                'url' => ['nullable', 'string', 'max:255'],
                'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
                'setup_token' => ['required', 'string', 'max:128'],
            ]);

            $setupToken = trim((string) $data['setup_token']);
            if (! $this->isValidSetupToken($setupToken)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Setup link invalid ya expire/used ho chuka hai. Naya one-time setup link chahiye.',
                ], 403);
            }

            $name = trim((string) $data['name']);
            $region = trim((string) ($data['region'] ?? ''));

            // Auto-generate id from name if not provided
            $id = strtolower(trim((string) ($data['id'] ?? '')));
            if ($id === '') {
                $id = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($name));
                $id = trim($id, '-_');
                if ($id === '') $id = 'node';
            }

            // Auto-determine port: next available after existing nodes
            $port = isset($data['port']) && $data['port'] ? (int) $data['port'] : 0;
            if ($port <= 0) {
                $maxPort = 8004;
                foreach ($this->nodes() as $n) {
                    $p = (int) (parse_url((string)($n['url'] ?? ''), PHP_URL_PORT) ?: 0);
                    if ($p > $maxPort) $maxPort = $p;
                }
                $port = $maxPort + 1;
                if ($port < 8005) $port = 8005;
            }

            // Auto-generate url if not provided
            $url = rtrim(trim((string) ($data['url'] ?? '')), '/');
            if ($url === '') {
                $host = $this->guessClientHost($request);
                $url = 'http://' . $host . ':' . $port;
            }

            $selfId = (string) config('dstore.node.id', 'node');
            if ($id === $selfId) {
                return response()->json(['ok' => false, 'error' => 'Node id same as current node.'], 422);
            }

            $nodesRoot = base_path('nodes');
            $nodeDir = $nodesRoot.'/'.$id;
            $storageRoot = $nodeDir.'/storage/dstore';

            if (is_dir($nodeDir)) {
                return response()->json(['ok' => false, 'error' => "Node directory already exists: nodes/{$id}"], 422);
            }

            $known = $this->nodes();
            if (isset($known[$id])) {
                // allow if directory missing but listed? treat as conflict
                return response()->json(['ok' => false, 'error' => 'Node id already known in this mesh.'], 422);
            }

            // 1) Generate identity + encrypted key for the new node
            $keyPair = sodium_crypto_sign_keypair();
            $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
            $secretKey = base64_encode(sodium_crypto_sign_secretkey($keyPair));
            $fingerprint = hash('sha256', $publicKey);
            $createdAt = gmdate('Y-m-d\TH:i:s\Z');

            $metaDir = $storageRoot.'/metadata';
            $keysDir = $metaDir.'/keys';
            foreach ([
                $storageRoot,
                $metaDir,
                $keysDir,
                $metaDir.'/counters',
                $metaDir.'/quarantine/events',
                $metaDir.'/tombstones',
                $storageRoot.'/records',
                $storageRoot.'/events/committed',
                $storageRoot.'/events/pending',
                $storageRoot.'/events/sync',
                $storageRoot.'/events/inbox',
                $storageRoot.'/indexes',
                $storageRoot.'/locks',
                $storageRoot.'/snapshots',
                $storageRoot.'/backups',
                $storageRoot.'/recovery',
            ] as $dir) {
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    return response()->json(['ok' => false, 'error' => "Unable to create directory: {$dir}"], 500);
                }
            }

            $appKey = (string) config('app.key', '');
            $encryptionKey = $this->deriveNodeEncryptionKey($appKey);
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($secretKey, $nonce, $encryptionKey);
            $keyFile = $keysDir.'/node.key';
            file_put_contents($keyFile, json_encode([
                'alg' => 'xsalsa20poly1305',
                'nonce' => base64_encode($nonce),
                'cipher' => base64_encode($cipher),
                'created_at' => $createdAt,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            @chmod($keyFile, 0600);

            $identity = [
                'node_id' => $id,
                'name' => $name,
                'region' => $region,
                'public_key' => $publicKey,
                'fingerprint' => $fingerprint,
                'created_at' => $createdAt,
                'url' => $url,
            ];
            file_put_contents($metaDir.'/node.json', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            // 2) Seed new node's peers (so it can trust/connect when started on new PC)
            $newPeers = [];
            $selfIdentity = $this->localIdentity();
            if ($selfIdentity !== null) {
                $newPeers[$selfIdentity['node_id']] = [
                    'id' => $selfIdentity['node_id'],
                    'name' => (string) ($selfIdentity['name'] ?? $selfIdentity['node_id']),
                    'url' => (string) ($selfIdentity['url'] ?? ''),
                    'region' => (string) ($selfIdentity['region'] ?? ''),
                    'public_key' => (string) ($selfIdentity['public_key'] ?? ''),
                    'fingerprint' => (string) ($selfIdentity['fingerprint'] ?? ''),
                    'status' => 'approved',
                    'enrolled_at' => $createdAt,
                    'approved_at' => $createdAt,
                    'revoked_at' => null,
                ];
            }
            foreach ($this->readPeersFile($this->localPeersPath()) as $pid => $peer) {
                if (!is_array($peer)) continue;
                $pid = (string) ($peer['id'] ?? $pid);
                if ($pid === '' || $pid === $id || isset($newPeers[$pid])) continue;
                if (($peer['status'] ?? '') !== 'approved' || empty($peer['public_key'])) continue;
                $newPeers[$pid] = $peer;
            }
            ksort($newPeers);
            file_put_contents($metaDir.'/peers.json', json_encode($newPeers, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            file_put_contents($metaDir.'/peer_status.json', json_encode(new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            file_put_contents($metaDir.'/conflicts.json', json_encode([], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            file_put_contents($metaDir.'/replay.json', json_encode(new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            file_put_contents($metaDir.'/health.json', json_encode([], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            file_put_contents($metaDir.'/runtime.json', json_encode([
                'epoch' => bin2hex(random_bytes(16)),
                'started_at' => $createdAt,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            file_put_contents($storageRoot.'/recovery/checkpoint.json', json_encode([
                'status' => 'initialized',
                'reason' => 'scaffolded-by-add-node',
                'started_at' => $createdAt,
                'finished_at' => $createdAt,
                'steps' => [],
                'error' => null,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            // 3) Register new node in current (Pakistan/local) mesh metadata so it appears in UI/dashboard
            $localPeersPath = $this->localPeersPath();
            $localPeers = $this->readPeersFile($localPeersPath);
            $localPeers[$id] = [
                'id' => $id,
                'name' => $name,
                'url' => $url,
                'region' => $region,
                'public_key' => $publicKey,
                'fingerprint' => $fingerprint,
                'status' => 'approved',
                'enrolled_at' => $createdAt,
                'approved_at' => $createdAt,
                'revoked_at' => null,
            ];
            ksort($localPeers);
            if (!is_dir(dirname($localPeersPath))) { @mkdir(dirname($localPeersPath), 0775, true); }
            file_put_contents($localPeersPath, json_encode($localPeers, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            $localStatusPath = $this->localMetadataPath('peer_status.json');
            $localStatus = $this->readPeersFile($localStatusPath);
            $localStatus[$id] = array_merge([
                'peer' => $id,
                'state' => 'unknown',
                'last_seen_at' => null,
                'last_response_ms' => null,
                'last_error' => null,
                'consecutive_failures' => 0,
                'updated_at' => $createdAt,
                'name' => $name,
                'region' => $region,
                'url' => $url,
                'epoch' => '',
                'started_at' => null,
                'reported_at' => null,
                'restarts' => 0,
                'last_restart_at' => null,
                'records' => null,
                'events_committed' => null,
                'storage_bytes' => null,
                'degraded' => false,
                'destroyed_at' => null,
            ], is_array($localStatus[$id] ?? null) ? $localStatus[$id] : []);
            $localStatus[$id]['name'] = $name;
            $localStatus[$id]['region'] = $region;
            $localStatus[$id]['url'] = $url;
            $localStatus[$id]['updated_at'] = $createdAt;
            ksort($localStatus);
            file_put_contents($localStatusPath, json_encode($localStatus, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            $cursorPath = $this->localStorageRoot().'/events/sync/'.$id.'.json';
            if (!is_file($cursorPath)) {
                @mkdir(dirname($cursorPath), 0775, true);
                file_put_contents($cursorPath, json_encode([
                    'peer' => $id,
                    'applied' => [],
                    'delivered' => [],
                    'pending_count' => 0,
                    'last_heartbeat_at' => null,
                    'last_sync_at' => null,
                    'state' => 'unknown',
                    'attempts' => 0,
                    'last_error' => null,
                    'failed' => false,
                ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }

            // One-time setup token consumed after successful add
            $this->consumeSetupToken($setupToken);

            return response()->json([
                'ok' => true,
                'node' => $identity,
                'storage_path' => $storageRoot,
                'registered_in' => $selfId,
                'dashboard_url' => route('dashboard.isolated', ['node' => $id], false),
                'setup_consumed' => true,
                'message' => "Node '{$name}' add ho gaya (one-time setup use ho chuka). Ab new PC par is node ko run/connect karna hoga.",
                'next_steps' => [
                    "Is node folder ko target PC par rakhein (nodes/{$id}/...) ya wahan project copy karke isi storage ko use karein.",
                    "Target PC par .env.{$id} banayein (DSTORE_NODE_ID={$id}, DSTORE_NODE_NAME, DSTORE_REGION, DSTORE_PUBLIC_URL={$url}, DSTORE_STORAGE_PATH=nodes/{$id}/storage/dstore, DSTORE_PEERS aur peer URLs).",
                    "Existing nodes ki peers/config mein bhi is naye node ko add/approve karein (mutual mesh/sync ke liye) — is node ki public_key/fingerprint upar di gayi hai.",
                    "Phir run karein: php artisan --env={$id} config:clear && php artisan --env={$id} serve --host=0.0.0.0 --port=".($port ?: '<port>')." (+ schedule:work, dstore:monitor).",
                    "PC3 dashboard par sirf PC3 dikhane ke liye .env.{$id} mein DSTORE_DASHBOARD_SELF_ONLY=true set karein (sync/peering phir bhi kaam karegi).",
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'Validation failed.', 'details' => $e->errors()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function localStorageRoot(): string
    {
        $nodeId = (string) config('dstore.node.id', 'node');
        $path = (string) config('dstore.storage.path', base_path('nodes/'.$nodeId.'/storage/dstore'));
        try {
            return \App\DStore\Storage\StoragePaths::make($path, base_path())->root();
        } catch (\Throwable) {
            return base_path('nodes/'.$nodeId.'/storage/dstore');
        }
    }

    private function localMetadataPath(string $file): string
    {
        return $this->localStorageRoot().'/metadata/'.$file;
    }

    private function localPeersPath(): string
    {
        return $this->localMetadataPath('peers.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function readPeersFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function localIdentity(): ?array
    {
        $path = $this->localMetadataPath('node.json');
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function deriveNodeEncryptionKey(string $appKey): string
    {
        $raw = $appKey;
        if ($raw === '') {
            throw new \RuntimeException('APP_KEY is not set; cannot encrypt node key.');
        }
        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            if ($decoded === false) {
                throw new \RuntimeException('APP_KEY is not valid base64.');
            }
            $raw = $decoded;
        }
        return hash_hkdf('sha256', $raw, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'dstore:node-secret-key:v1');
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

    private function setupTokenPath(): string
    {
        return $this->localMetadataPath('setup_token.json');
    }

    /**
     * @return array{token?:string,created_at?:string,used_at?:?string}|null
     */
    private function readSetupToken(): ?array
    {
        $path = $this->setupTokenPath();
        if (!is_file($path)) return null;
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function isValidSetupToken(string $token): bool
    {
        $token = trim($token);
        if ($token === '') return false;
        $data = $this->readSetupToken();
        if (!is_array($data)) return false;
        if (($data['token'] ?? '') !== $token) return false;
        if (!empty($data['used_at'])) return false;
        return true;
    }

    private function consumeSetupToken(string $token): void
    {
        $path = $this->setupTokenPath();
        $data = $this->readSetupToken();
        if (!is_array($data) || ($data['token'] ?? '') !== $token) return;
        $data['used_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $data['used_by'] = $token;
        @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    private function guessClientHost(Request $request): string
    {
        // Best-effort: when URL is auto-generated, prefer a reachable host hint.
        $xff = (string) $request->header('X-Forwarded-For', '');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if ($first !== '' && !in_array($first, ['127.0.0.1', '::1'], true)) {
                return $first;
            }
        }
        $ip = (string) $request->ip();
        if ($ip !== '' && !in_array($ip, ['127.0.0.1', '::1'], true)) {
            return $ip;
        }
        return '127.0.0.1';
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

        foreach ($this->storedPeers() as $id => $peer) {
            if (!isset($nodes[$id])) {
                $nodes[$id] = [
                    'id' => (string) ($peer['id'] ?? $id),
                    'name' => (string) ($peer['name'] ?? Str::headline((string) $id)),
                    'url' => (string) ($peer['url'] ?? ''),
                ];
            } elseif (($nodes[$id]['url'] ?? '') === '' && !empty($peer['url'])) {
                $nodes[$id]['url'] = (string) $peer['url'];
            } elseif (!empty($peer['url']) && $this->isLocalUrl((string) ($nodes[$id]['url'] ?? ''))) {
                $nodes[$id]['url'] = (string) $peer['url'];
            }
            if (($nodes[$id]['name'] ?? '') === Str::headline((string) $id) && !empty($peer['name'])) {
                $nodes[$id]['name'] = (string) $peer['name'];
            }
        }

        // No hardcoded peers: nodes appear only when explicitly configured
        // (DSTORE_PEERS / stored peers) or added via "Add node".
        foreach (['uae', 'usa'] as $disabled) {
            unset($nodes[$disabled]);
        }

        return $nodes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function storedPeers(): array
    {
        try {
            $path = base_path('nodes/'.((string) config('dstore.node.id', 'node')).'/storage/dstore/metadata/peers.json');
            if (!is_file($path)) {
                return [];
            }
            $decoded = json_decode((string) @file_get_contents($path), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function isLocalUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
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