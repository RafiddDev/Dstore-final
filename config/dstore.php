<?php

$peers = [];

foreach (array_filter(array_map('trim', explode(',', (string) env('DSTORE_PEERS', '')))) as $peerId) {
    $url = env('DSTORE_PEER_'.strtoupper($peerId).'_URL');

    if (is_string($url) && $url !== '') {
        $peers[$peerId] = ['id' => $peerId, 'url' => rtrim($url, '/')];
    }
}

return [
    'node' => [
        'id' => env('DSTORE_NODE_ID', 'node1'),
        'name' => env('DSTORE_NODE_NAME', 'Node 1'),
        'region' => env('DSTORE_REGION', 'local'),
        'public_url' => env('DSTORE_PUBLIC_URL', 'http://127.0.0.1:8000'),
        'port' => (int) env('DSTORE_PORT', 8000),
    ],

    'storage' => [
        'path' => env('DSTORE_STORAGE_PATH', storage_path('dstore')),
        'collections' => [
            'users' => 'usr',
            'products' => 'prd',
            'documents' => 'doc',
        ],
        'tombstone_gc_days' => (int) env('DSTORE_TOMBSTONE_GC_DAYS', 7),
        'backup_retention' => (int) env('DSTORE_BACKUP_RETENTION', 0),
    ],

    'peers' => $peers,

    'network' => [
        'timeout' => (int) env('DSTORE_HTTP_TIMEOUT', 10),
        'max_attempts' => (int) env('DSTORE_HTTP_MAX_ATTEMPTS', 3),
    ],

    'security' => [
        'bootstrap_token' => env('DSTORE_BOOTSTRAP_TOKEN'),
        'registration_mode' => env('DSTORE_REGISTRATION_MODE', 'token'),
        'replay_window' => (int) env('DSTORE_REPLAY_WINDOW', 300),
        'max_clock_skew' => (int) env('DSTORE_MAX_CLOCK_SKEW', 300),
        'require_signed_events' => (bool) env('DSTORE_REQUIRE_SIGNED_EVENTS', true),
        'allow_rekey' => (bool) env('DSTORE_ALLOW_REKEY', true),
    ],

    'monitoring' => [
        'heartbeat_interval' => (int) env('DSTORE_HEARTBEAT_INTERVAL', 5),
        'heartbeat_timeout' => (int) env('DSTORE_HEARTBEAT_TIMEOUT', 15),
        'misses_before_offline' => (int) env('DSTORE_MISSES_BEFORE_OFFLINE', 3),
        'gossip' => (bool) env('DSTORE_GOSSIP', true),
    ],

    'replication' => [
        'retry_base_delay' => (int) env('DSTORE_RETRY_BASE_DELAY', 2),
        'retry_max_delay' => (int) env('DSTORE_RETRY_MAX_DELAY', 300),
        'event_max_attempts' => (int) env('DSTORE_EVENT_MAX_ATTEMPTS', 10),
        'push_batch_size' => (int) env('DSTORE_PUSH_BATCH_SIZE', 100),
    ],

    'consistency' => [
        'policy' => env('DSTORE_CONFLICT_POLICY', 'vector-lww'),
        'node_priority' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DSTORE_NODE_PRIORITY', '')),
        ))),
        'conflict_log_limit' => (int) env('DSTORE_CONFLICT_LOG_LIMIT', 200),
        'history_limit' => (int) env('DSTORE_HISTORY_LIMIT', 50),
    ],

    'recovery' => [
        'auto_snapshot_restore' => (bool) env('DSTORE_AUTO_SNAPSHOT_RESTORE', false),
        'backup_before_restore' => (bool) env('DSTORE_BACKUP_BEFORE_RESTORE', false),
    ],

    'dashboard' => [
        // When true, this node's dashboard/UI shows only itself (no other nodes).
        // Peering/sync can still work in the background.
        'self_only' => (bool) env('DSTORE_DASHBOARD_SELF_ONLY', false),
    ],
];
