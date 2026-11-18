@extends('dashboard.layout')

@php
    $bytes = function ($value) {
        if ($value === null || $value === '') return '—';
        $n = (float) $value;
        if ($n < 1024) return number_format($n) . ' B';
        $units = ['KB', 'MB', 'GB', 'TB'];
        $i = -1;
        do { $n /= 1024; $i++; } while ($n >= 1024 && $i < count($units) - 1);
        return number_format($n, $n >= 10 ? 0 : 1) . ' ' . $units[$i];
    };
    $duration = function ($seconds) {
        if ($seconds === null || $seconds === '') return '—';
        $s = max(0, (int) $seconds);
        if ($s < 60) return $s . ' s';
        $m = intdiv($s, 60);
        if ($m < 60) return $m . 'm ' . ($s % 60) . 's';
        $h = intdiv($m, 60);
        if ($h < 24) return $h . 'h ' . ($m % 60) . 'm';
        return intdiv($h, 24) . 'd ' . ($h % 24) . 'h';
    };
    $time = function ($iso) {
        if (!$iso) return '—';
        $ts = strtotime($iso);
        return $ts === false ? $iso : gmdate('Y-m-d H:i:s', $ts) . ' UTC';
    };
    $ago = function ($iso) {
        if (!$iso) return '—';
        $ts = strtotime($iso);
        if ($ts === false) return $iso;
        $s = max(0, time() - $ts);
        if ($s < 60) return $s . ' s ago';
        if ($s < 3600) return intdiv($s, 60) . 'm ago';
        if ($s < 86400) return intdiv($s, 3600) . 'h ago';
        return intdiv($s, 86400) . 'd ago';
    };
@endphp

@section('title', $node['name'])
@section('page-attributes')
    data-page="node" data-node="{{ $node['node_id'] }}"
@endsection

@section('content')
    <div class="page-head">
        <div>
            <div class="breadcrumb">
                <a href="{{ route('dashboard') }}">Network overview</a> / {{ $node['name'] }}
            </div>
            <h2>
                {{ $node['name'] }}
                <span class="badge {{ $node['state'] }}" style="vertical-align: middle;" data-state-badge>{{ $node['state'] }}</span>
            </h2>
            <p>
                @if ($isSelf)
                    This node — the process answering this dashboard. Its state is computed from the
                    storage engine, not from a heartbeat.
                @else
                    Peer view as observed by this node.
                    Every value comes from the last signed heartbeat or the local sync cursor for this peer.
                @endif
            </p>
        </div>
        <div class="actions">
            @if ($isSelf)
                <a class="button" href="{{ route('dashboard') }}">Back to mesh</a>
            @else
                <button id="probe-button" data-node="{{ $node['node_id'] }}">Probe now</button>
                <a class="button" href="{{ route('dashboard') }}">Back to mesh</a>
            @endif
        </div>
    </div>

    <div id="probe-result" class="flash"></div>

    <section class="grid three">
        <div class="card">
            <h3>Identity</h3>
            <dl class="kv">

                <dt>Name</dt>
                <dd data-field="name">{{ $node['name'] }}</dd>
                <dt>Region</dt>
                <dd data-field="region">{{ $node['region'] ?: '—' }}</dd>
                <dt>Public URL</dt>
                <dd class="mono" data-field="url">{{ $node['url'] ?: '—' }}</dd>
                <dt>Trust</dt>
                <dd><span class="badge {{ $node['trust'] }}">{{ $node['trust'] }}</span></dd>
                <dt>Fingerprint</dt>
                <dd class="mono" data-field="fingerprint">{{ $node['fingerprint'] ?: '—' }}</dd>
                <dt>Runtime epoch</dt>
                <dd class="mono" data-field="epoch">{{ $node['epoch'] ?: '—' }}</dd>
                <dt>Started</dt>
                <dd data-field="started_at" data-format="time">{{ $time($node['started_at']) }}</dd>
                <dt>Uptime</dt>
                <dd data-field="uptime_seconds" data-format="duration">{{ $duration($node['uptime_seconds']) }}</dd>
                <dt>Restarts seen</dt>
                <dd data-field="restarts" data-format="number">{{ $node['restarts'] }}</dd>
                <dt>Last restart</dt>
                <dd data-field="last_restart_at" data-format="time">{{ $time($node['last_restart_at']) }}</dd>
            </dl>
        </div>

        <div class="card">
            <h3>Health</h3>
            <dl class="kv">
                <dt>Detected state</dt>
                <dd><span class="badge {{ $node['state'] }}" data-state-badge>{{ $node['state'] }}</span></dd>
                <dt>Reachable</dt>
                <dd>
                    @if ($node['reachable'])
                        <span class="badge online">yes</span>
                    @else
                        <span class="badge offline">no</span>
                    @endif
                </dd>
                <dt>Latency</dt>
                <dd data-field="latency_ms" data-format="latency">{{ $node['latency_ms'] === null ? '—' : number_format((float) $node['latency_ms'], 1) . ' ms' }}</dd>
                <dt>Last seen</dt>
                <dd data-field="last_seen_at" data-format="time">{{ $time($node['last_seen_at']) }}</dd>
                <dt>Silent for</dt>
                <dd data-field="silent_for" data-format="duration">{{ $duration($node['silent_for']) }}</dd>
                <dt>Consecutive misses</dt>
                <dd data-field="consecutive_failures" data-format="number">{{ $node['consecutive_failures'] }}</dd>
                <dt>Last error</dt>
                <dd data-field="last_error">{{ $node['last_error'] ?: '—' }}</dd>
                <dt>Sync state</dt>
                <dd class="mono" data-field="sync_state">{{ $node['sync_state'] ?: '—' }}</dd>
                <dt>Last sync</dt>
                <dd data-field="last_sync_at" data-format="time">{{ $time($node['last_sync_at']) }}</dd>
                <dt>Reason</dt>
                <dd class="muted">{{ $node['reason'] ?? '—' }}</dd>
            </dl>
        </div>

        <div class="card">
            <h3>Data</h3>
            <dl class="kv">
                <dt>Records</dt>
                <dd data-field="records" data-format="number">{{ $node['records'] === null ? '—' : number_format((int) $node['records']) }}</dd>
                <dt>Events committed</dt>
                <dd data-field="events_committed" data-format="number">{{ $node['events_committed'] === null ? '—' : number_format((int) $node['events_committed']) }}</dd>
                <dt>Events applied</dt>
                <dd data-field="events_applied" data-format="number">{{ $node['events_applied'] === null ? '—' : number_format((int) $node['events_applied']) }}</dd>
                <dt>Pending events</dt>
                <dd data-field="pending_total" data-format="number">{{ number_format((int) $node['pending_total']) }}</dd>
                <dt>Storage</dt>
                <dd data-field="storage_bytes" data-format="bytes">{{ $bytes($node['storage_bytes']) }}</dd>
                @if ($isSelf)
                    <dt>Resolved conflicts</dt>
                    <dd data-field="conflicts" data-format="number">{{ number_format((int) ($node['conflicts'] ?? 0)) }}</dd>
                @endif
                <dt>Degraded</dt>
                <dd>
                    @if ($node['degraded'])
                        <span class="badge degraded">yes</span>
                    @else
                        <span class="badge online">no</span>
                    @endif
                </dd>
                <dt>Destroyed</dt>
                <dd>
                    @if ($node['destroyed'])
                        <span class="badge destroyed">yes</span>
                    @else
                        <span class="muted">no</span>
                    @endif
                </dd>
            </dl>
        </div>
    </section>

    <section class="card mt">
        <h3>Replication vectors</h3>
        <div class="grid two">
            <div>
                <div class="muted" style="margin-bottom:6px;">Applied by this node (contiguous prefix per origin)</div>
                <pre class="vector" data-field="applied_vector" data-format="json">{{ json_encode($node['applied_vector'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            @if (!$isSelf)
                <div>
                    <div class="muted" style="margin-bottom:6px;">Delivered by this node to the peer (confirmed by its applied vector)</div>
                    <pre class="vector" data-field="delivered_vector" data-format="json">{{ json_encode($node['delivered_vector'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            @endif
        </div>
        <p class="hint">
            A vector entry is a contiguous prefix: a node never reports an event it cannot serve.
            That is why a hole can never be mistaken for progress.
        </p>
    </section>

    @if ($isSelf)
        <section class="grid two mt">
            <div class="card">
                <h3>Collections on this node</h3>
                @if (($node['collections'] ?? []) === [])
                    <div class="empty">No records have been written yet.</div>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Collection</th><th class="num">Records</th></tr></thead>
                            <tbody>
                            @foreach ($node['collections'] as $collection => $count)
                                <tr>
                                    <td class="mono">{{ $collection }}</td>
                                    <td class="num">{{ number_format((int) $count) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <h3 class="mt">Pending per peer</h3>
                @if (($node['pending'] ?? []) === [])
                    <div class="empty">No peer queues. Either this node has no peers or every event is delivered.</div>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Peer</th><th class="num">Pending events</th></tr></thead>
                            <tbody>
                            @foreach ($node['pending'] as $peer => $pending)
                                <tr>
                                    <td class="mono">{{ $peer }}</td>
                                    <td class="num">{{ number_format((int) $pending) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="card">
                <h3>Storage health</h3>
                @if ($node['degraded'])
                    <p><span class="badge degraded">degraded</span> The engine has quarantined at least one file.</p>
                    @if (($node['issues'] ?? []) !== [])
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>#</th><th>Issue</th></tr></thead>
                                <tbody>
                                @foreach ($node['issues'] as $index => $issue)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td class="mono">{{ is_array($issue) ? json_encode($issue) : $issue }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @else
                    <p><span class="badge online">clean</span> Every record, event and snapshot hash matches.</p>
                @endif
                <p class="hint">
                    Repair with <span class="mono">dstore:verify</span>, <span class="mono">dstore:recover</span>
                    or <span class="mono">dstore:records:restore</span>. Corruption is quarantined, never served.
                </p>
            </div>
        </section>

        <section class="grid two mt">
            <div class="card">
                <h3>Backups</h3>
                @if ($backups === [])
                    <div class="empty">No backups yet. Run <span class="mono">dstore:backup</span>.</div>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Name</th><th class="num">Files</th><th class="num">Bytes</th><th>Created</th></tr></thead>
                            <tbody>
                            @foreach ($backups as $backup)
                                <tr>
                                    <td class="mono">{{ $backup['name'] ?? '—' }}</td>
                                    <td class="num">{{ number_format((int) ($backup['count'] ?? 0)) }}</td>
                                    <td class="num">{{ $bytes($backup['bytes'] ?? 0) }}</td>
                                    <td title="{{ $backup['created_at'] ?? '' }}">{{ $ago($backup['created_at'] ?? null) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="card">
                <h3>Recovery</h3>
                @php($lastRecovery = $recovery)
                @if ($lastRecovery === null)
                    <div class="empty">Recovery has never run on this node.</div>
                @else
                    <dl class="kv">
                        <dt>Status</dt>
                        <dd><span class="badge {{ $lastRecovery['status'] ?? 'unknown' }}">{{ $lastRecovery['status'] ?? 'unknown' }}</span></dd>
                        <dt>Reason</dt>
                        <dd>{{ $lastRecovery['reason'] ?? '—' }}</dd>
                        <dt>Started</dt>
                        <dd>{{ $lastRecovery['started_at'] ?? '—' }}</dd>
                        <dt>Finished</dt>
                        <dd>{{ $lastRecovery['finished_at'] ?? '—' }}</dd>
                        <dt>Steps</dt>
                        <dd>
                            @foreach (($lastRecovery['steps'] ?? []) as $step)
                                <span class="chip">{{ $step['step'] ?? '?' }}
                                    <strong>{{ !empty($step['ok']) ? 'ok' : 'failed' }}</strong>
                                    @if (isset($step['duration_ms'])) {{ $step['duration_ms'] }} ms @endif
                                </span>
                            @endforeach
                        </dd>
                        @if (!empty($lastRecovery['error']))
                            <dt>Error</dt>
                            <dd><span class="badge offline">{{ $lastRecovery['error'] }}</span></dd>
                        @endif
                    </dl>
                @endif
            </div>
        </section>

        <section class="card mt">
            <h3>Recent conflicts</h3>
            @if ($conflicts === [])
                <div class="empty">No concurrent writes have needed resolution on this node.</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Record</th><th>Winner</th><th>Versions</th><th>Reason</th><th>When</th></tr></thead>
                        <tbody>
                        @foreach ($conflicts as $conflict)
                            <tr>
                                <td class="mono">{{ $conflict['collection'] ?? '—' }}/{{ $conflict['record_id'] ?? '—' }}</td>
                                <td><span class="badge online">{{ $conflict['winner_writer'] ?? '—' }}</span></td>
                                <td class="mono muted">
                                    {{ json_encode($conflict['local_version'] ?? []) }}
                                    vs
                                    {{ json_encode($conflict['incoming_version'] ?? []) }}
                                </td>
                                <td class="muted">{{ $conflict['reason'] ?? '—' }}</td>
                                <td title="{{ $conflict['detected_at'] ?? '' }}">{{ $ago($conflict['detected_at'] ?? null) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @else
        <section class="card mt">
            <h3>What this peer is missing</h3>
            <p class="muted">
                The cursor records what this node has delivered (<span class="mono">delivered_vector</span>)
                and what the peer reported it has applied (<span class="mono">applied_vector</span>).
                A non-zero pending count means a sync pass still has work to do.
            </p>
            <div class="actions">
                <span class="chip">pending <strong data-field="pending_total" data-format="number">{{ number_format((int) $node['pending_total']) }}</strong></span>
                <span class="chip">sync <strong data-field="sync_state">{{ $node['sync_state'] ?: '—' }}</strong></span>
                <span class="chip">last sync <strong data-field="last_sync_at" data-format="time">{{ $time($node['last_sync_at']) }}</strong></span>
            </div>
            @if (!empty($node['sync_error']))
                <p class="hint">Last sync error: <span class="badge offline">{{ $node['sync_error'] }}</span></p>
            @endif
            <p class="hint">
                Press <strong>Probe now</strong> to send one signed heartbeat immediately. The
                background <span class="mono">dstore:monitor</span> does the same every interval.
            </p>
        </section>
    @endif
@endsection
