@extends('dashboard.layout')

@php
    $node = $node ?? [];
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
@endphp

@section('title', ($node['name'] ?? 'Node') . ' — Isolated Dashboard')
@section('page-attributes')
    data-page="isolated" data-node="{{ $node['node_id'] ?? '' }}"
@endsection

@section('content')
    <div class="page-head">
        <div>
            <div class="breadcrumb">
                <a href="{{ route('dashboard') }}">Network overview</a> / Isolated / {{ $node['name'] ?? 'Node' }}
            </div>
            <h2>
                {{ $node['name'] ?? 'Node' }}
                <span class="badge {{ $node['state'] ?? 'unknown' }}" style="vertical-align: middle;">{{ $node['state'] ?? 'unknown' }}</span>
            </h2>
            <p>
                Isolated dashboard — sirf yeh node ka data. Koi dusra node (Pakistan/UK) nahi dikhega.
            </p>
        </div>
        <div class="actions">
            <a class="button" href="{{ route('dashboard') }}">Back to mesh</a>
            <a class="button" href="{{ route('dashboard.node', ['node' => $node['node_id']]) }}">Full detail</a>
        </div>
    </div>

    <section class="grid three">
        <div class="card">
            <h3>Identity</h3>
            <dl class="kv">

                <dt>Name</dt><dd>{{ $node['name'] ?? '—' }}</dd>
                <dt>Region</dt><dd>{{ $node['region'] ?: '—' }}</dd>
                <dt>Public URL</dt><dd class="mono">{{ $node['url'] ?: '—' }}</dd>
                <dt>Trust</dt><dd><span class="badge {{ $node['trust'] ?? 'unknown' }}">{{ $node['trust'] ?? '—' }}</span></dd>
                <dt>Fingerprint</dt><dd class="mono">{{ $node['fingerprint'] ?: '—' }}</dd>
                <dt>State</dt><dd><span class="badge {{ $node['state'] ?? 'unknown' }}">{{ $node['state'] ?? '—' }}</span></dd>
            </dl>
        </div>

        <div class="card">
            <h3>Health</h3>
            <dl class="kv">
                <dt>Reachable</dt><dd>{{ ($node['reachable'] ?? false) ? 'yes' : 'no' }}</dd>
                <dt>Latency</dt><dd>{{ $node['latency_ms'] === null ? '—' : number_format((float) $node['latency_ms'], 1) . ' ms' }}</dd>
                <dt>Last seen</dt><dd>{{ $time($node['last_seen_at'] ?? null) }}</dd>
                <dt>Consecutive misses</dt><dd>{{ number_format((int) ($node['consecutive_failures'] ?? 0)) }}</dd>
                <dt>Last error</dt><dd>{{ $node['last_error'] ?: '—' }}</dd>
                <dt>Sync state</dt><dd class="mono">{{ $node['sync_state'] ?: '—' }}</dd>
                <dt>Last sync</dt><dd>{{ $time($node['last_sync_at'] ?? null) }}</dd>
                <dt>Pending events</dt><dd>{{ number_format((int) ($node['pending_total'] ?? 0)) }}</dd>
            </dl>
        </div>

        <div class="card">
            <h3>Data</h3>
            <dl class="kv">
                <dt>Records</dt><dd>{{ $node['records'] === null ? '—' : number_format((int) $node['records']) }}</dd>
                <dt>Events committed</dt><dd>{{ $node['events_committed'] === null ? '—' : number_format((int) $node['events_committed']) }}</dd>
                <dt>Events applied</dt><dd>{{ $node['events_applied'] === null ? '—' : number_format((int) $node['events_applied']) }}</dd>
                <dt>Storage</dt><dd>{{ $bytes($node['storage_bytes'] ?? null) }}</dd>
                <dt>Degraded</dt><dd>{{ ($node['degraded'] ?? false) ? 'yes' : 'no' }}</dd>
                <dt>Destroyed</dt><dd>{{ ($node['destroyed'] ?? false) ? 'yes' : 'no' }}</dd>
            </dl>
            <p class="hint" style="margin-top:12px;">
                Isolated view: sirf <strong>{{ $node['name'] ?? 'this node' }}</strong> ka data.
                Koi dusra node (Pakistan/UK) yahan nahi dikhega.
            </p>
        </div>
    </section>
@endsection