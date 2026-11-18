@extends('dashboard.layout')

@php
    $self = $overview['node'];
    $summary = $overview['summary'];
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

@section('title', 'Network overview')
@section('page-attributes')
    data-page="overview"
@endsection

@section('content')
    <div class="page-head">
        <div>
            <div class="breadcrumb">D-STORE · four-node mesh</div>
            <h2>Network overview</h2>
            <p>
                Live view from <strong>{{ $self['name'] }}</strong> ({{ $self['node_id'] }}).
                Every figure below is read from this node's storage, its signed heartbeat
                records, or the event cursors it keeps per peer.
            </p>
        </div>
        <div class="actions">
            <span class="chip"><span class="dot {{ $summary['mesh'] === 'healthy' ? 'online' : ($summary['mesh'] === 'degraded' ? 'degraded' : 'unknown') }}"></span>
                mesh <strong data-stat="mesh">{!! '<span class="badge '.$summary['mesh'].'">'.$summary['mesh'].'</span>' !!}</strong>
            </span>
        </div>
    </div>

    <section class="grid cards">
        <div class="card stat">
            <div class="label">Mesh health</div>
            <div class="value small" data-stat="mesh"><span class="badge {{ $summary['mesh'] }}">{{ $summary['mesh'] }}</span></div>
            <div class="sub">{{ $summary['trusted'] }} trusted · {{ $summary['degraded'] }} degraded · {{ $summary['offline'] }} offline</div>
        </div>
        <div class="card stat">
            <div class="label">Nodes online</div>
            <div class="value"><span data-stat="online">{{ $summary['online'] }}</span> <span class="muted" style="font-size:16px;">/ <span data-stat="total">{{ $summary['total'] }}</span></span></div>
            <div class="sub"><span data-stat="unreachable">{{ $summary['unreachable'] }}</span> unreachable · <span data-stat="unknown">{{ $summary['unknown'] }}</span> unknown</div>
        </div>
        <div class="card stat">
            <div class="label">Records</div>
            <div class="value" data-stat="records" data-format="number">{{ $self['records'] }}</div>
            <div class="sub"><span data-stat="events_committed" data-format="number">{{ $self['events_committed'] }}</span> events committed locally</div>
        </div>
        <div class="card stat">
            <div class="label">Pending events</div>
            <div class="value" data-stat="pending_total">{{ $summary['pending_total'] }}</div>
            <div class="sub">queued for delivery to peers</div>
        </div>
        <div class="card stat">
            <div class="label">Resolved conflicts</div>
            <div class="value" data-stat="conflicts">{{ $summary['conflicts'] }}</div>
            <div class="sub">kept in <span class="mono">metadata/conflicts.json</span></div>
        </div>
        <div class="card stat">
            <div class="label">Storage used</div>
            <div class="value small" data-stat="storage_bytes" data-format="bytes">{{ $bytes($self['storage_bytes']) }}</div>
            <div class="sub">records, events, indexes and snapshots</div>
        </div>
    </section>

    <section class="grid two mt">
        <div class="card">
            <h3>This node</h3>
            <dl class="kv">
                <dt>Name / id</dt>
                <dd><span class="name" data-self="name">{{ $self['name'] }}</span> <span class="muted mono" data-self="node_id">{{ $self['node_id'] }}</span></dd>
                <dt>State</dt>
                <dd><span class="badge {{ $self['state'] }}" data-self-badge>{{ $self['state'] }}</span></dd>
                <dt>Region</dt>
                <dd data-self="region">{{ $self['region'] ?: '—' }}</dd>
                <dt>Public URL</dt>
                <dd class="mono" data-self="url">{{ $self['url'] ?: '—' }}</dd>
                <dt>Fingerprint</dt>
                <dd class="mono" data-self="fingerprint">{{ $self['fingerprint'] }}</dd>
                <dt>Runtime epoch</dt>
                <dd class="mono" data-self="epoch">{{ $self['epoch'] }}</dd>
                <dt>Started</dt>
                <dd data-self="started_at" data-format="time">{{ $self['started_at'] }}</dd>
                <dt>Uptime</dt>
                <dd data-self="uptime_seconds" data-format="duration">{{ $duration($self['uptime_seconds']) }}</dd>
                <dt>Collections</dt>
                <dd>
                    @forelse ($self['collections'] as $collection => $count)
                        <span class="chip">{{ $collection }} <strong>{{ $count }}</strong></span>
                    @empty
                        <span class="muted">no records yet</span>
                    @endforelse
                </dd>
            </dl>
            <p class="hint">
                This node is also a peer to the others. Open
                <a href="{{ route('dashboard.node', ['node' => $self['node_id']]) }}">its detail page</a>
                for applied vectors, pending queues, backups and recovery history.
            </p>
        </div>

        <div class="card">
            <h3>Replication at a glance</h3>
            <dl class="kv">
                <dt>Events applied</dt>
                <dd data-self="events_applied" data-format="number">{{ $self['events_applied'] }}</dd>
                <dt>Events committed</dt>
                <dd data-self="events_committed" data-format="number">{{ $self['events_committed'] }}</dd>
                <dt>Pending total</dt>
                <dd data-self="pending_total" data-format="number">{{ $self['pending_total'] }}</dd>
                <dt>Per peer</dt>
                <dd>
                    @forelse ($self['pending'] as $peer => $pending)
                        <span class="chip">{{ $peer }} <strong>{{ $pending }}</strong></span>
                    @empty
                        <span class="muted">no peers configured</span>
                    @endforelse
                </dd>
                <dt>Storage issues</dt>
                <dd>
                    @if ($self['degraded'])
                        <span class="badge degraded">degraded</span>
                        <span class="muted">{{ count($self['issues']) }} recorded issue(s)</span>
                    @else
                        <span class="badge online">clean</span>
                    @endif
                </dd>
            </dl>
            <p class="hint">
                Delivery is confirmed by a peer's applied vector, never by an HTTP status.
                Pending counts therefore lag behind the network by one sync pass at most.
            </p>
        </div>
    </section>

    <section class="card mt">
        <h3>Nodes</h3>
        <div class="table-wrap">
            <table class="nodes-table">
                <thead>
                <tr>
                    <th>Node</th>
                    <th>Region</th>
                    <th>Trust</th>
                    <th>Detected state</th>
                    <th class="num">Latency</th>
                    <th class="num">Uptime</th>
                    <th class="num">Records</th>
                    <th class="num">Events</th>
                    <th class="num">Storage</th>
                    <th>Last seen</th>
                </tr>
                </thead>
                <tbody id="nodes-body">
                @foreach ($overview['nodes'] as $peer)
                    <tr>
                        <td>
                            <a class="name" href="{{ route('dashboard.node', ['node' => $peer['node_id']]) }}">{{ $peer['name'] }}</a>
                            @if ($peer['role'] === 'self')<span class="ident">this node</span>@endif
                        </td>
                        <td>{{ $peer['region'] ?: '—' }}</td>
                        <td><span class="badge {{ $peer['trust'] }}">{{ $peer['trust'] }}</span></td>
                        <td><span class="badge {{ $peer['state'] }}">{{ $peer['state'] }}</span></td>
                        <td class="num">{{ $peer['latency_ms'] === null ? '—' : number_format((float) $peer['latency_ms'], 1) . ' ms' }}</td>
                        <td class="num">{{ $duration($peer['uptime_seconds']) }}</td>
                        <td class="num">{{ $peer['records'] === null ? '—' : number_format((int) $peer['records']) }}</td>
                        <td class="num">{{ $peer['events_committed'] === null ? '—' : number_format((int) $peer['events_committed']) }}</td>
                        <td class="num">{{ $bytes($peer['storage_bytes']) }}</td>
                        <td title="{{ $peer['last_seen_at'] }}">{{ $peer['role'] === 'self' ? 'now' : $ago($peer['last_seen_at']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="hint">
            A peer turns <span class="badge unreachable">unreachable</span> after one missed
            heartbeat and <span class="badge offline">offline</span> after three. Only an
            administrator can mark a node <span class="badge destroyed">destroyed</span>; the
            monitor never does.
        </p>
    </section>

    <section class="grid two mt">
        <div class="card">
            <h3>Recent conflicts</h3>
            @if ($overview['conflicts'] === [])
                <div class="empty">No concurrent writes have needed resolution on this node.</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Record</th>
                            <th>Winner</th>
                            <th>Losers</th>
                            <th>Reason</th>
                            <th>When</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($overview['conflicts'] as $conflict)
                            <tr>
                                <td>
                                    <span class="name">{{ $conflict['collection'] ?? '—' }}/{{ $conflict['record_id'] ?? '—' }}</span>
                                    <span class="ident mono">{{ \Illuminate\Support\Str::limit((string) ($conflict['event_id'] ?? ''), 18) }}</span>
                                </td>
                                <td><span class="badge online">{{ $conflict['winner_writer'] ?? '—' }}</span></td>
                                <td class="muted">
                                    {{ $conflict['local_writer'] ?? '—' }} / {{ $conflict['incoming_writer'] ?? '—' }}
                                </td>
                                <td class="muted">{{ $conflict['reason'] ?? '—' }}</td>
                                <td title="{{ $conflict['detected_at'] ?? '' }}">{{ $ago($conflict['detected_at'] ?? null) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="card">
            <h3>Backups</h3>
            @if ($overview['backups'] === [])
                <div class="empty">No backups on this node yet. Run <span class="mono">dstore:backup</span>.</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th class="num">Files</th>
                            <th class="num">Bytes</th>
                            <th>Created</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($overview['backups'] as $backup)
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
    </section>

    <section class="card mt">
        <h3>Last recovery</h3>
        @php($recovery = $overview['recovery'])
        @if ($recovery === null)
            <div class="empty">Recovery has never run on this node.</div>
        @else
            <dl class="kv">
                <dt>Status</dt>
                <dd><span class="badge {{ $recovery['status'] ?? 'unknown' }}">{{ $recovery['status'] ?? 'unknown' }}</span></dd>
                <dt>Reason</dt>
                <dd>{{ $recovery['reason'] ?? '—' }}</dd>
                <dt>Started</dt>
                <dd>{{ $recovery['started_at'] ?? '—' }}</dd>
                <dt>Finished</dt>
                <dd>{{ $recovery['finished_at'] ?? '—' }}</dd>
                <dt>Steps</dt>
                <dd>
                    @foreach (($recovery['steps'] ?? []) as $step)
                        <span class="chip">{{ $step['step'] ?? '?' }}
                            <strong>{{ !empty($step['ok']) ? 'ok' : 'failed' }}</strong>
                        </span>
                    @endforeach
                </dd>
                @if (!empty($recovery['error']))
                    <dt>Error</dt>
                    <dd class="badge offline">{{ $recovery['error'] }}</dd>
                @endif
            </dl>
        @endif
        <p class="hint">
            Open <a href="{{ route('dashboard.node', ['node' => $self['node_id']]) }}">this node's detail page</a>
            for the checkpoint history and per-step timings.
        </p>
    </section>
@endsection
