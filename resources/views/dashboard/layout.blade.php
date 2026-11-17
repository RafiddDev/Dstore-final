<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'D-STORE') · D-STORE</title>
    <style>
        :root {
            --bg: #08111f;
            --panel: #0f1b2d;
            --panel-2: #14233a;
            --panel-3: #1a2c47;
            --line: #23374f;
            --text: #e6eefb;
            --muted: #8ea3c0;
            --accent: #38bdf8;
            --accent-2: #818cf8;
            --ok: #34d399;
            --warn: #fbbf24;
            --bad: #f87171;
            --orange: #fb923c;
            --unknown: #94a3b8;
            --radius: 14px;
        }

        * { box-sizing: border-box; }

        html, body { margin: 0; padding: 0; }

        body {
            background: radial-gradient(1200px 600px at 15% -10%, #102b45 0%, transparent 60%), var(--bg);
            color: var(--text);
            font-family: "Segoe UI", system-ui, -apple-system, "Helvetica Neue", Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            min-height: 100vh;
        }

        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .shell { max-width: 1280px; margin: 0 auto; padding: 0 24px 64px; }

        header.topbar {
            position: sticky;
            top: 0;
            z-index: 20;
            backdrop-filter: blur(12px);
            background: rgba(8, 17, 31, .78);
            border-bottom: 1px solid var(--line);
        }

        .topbar-inner {
            max-width: 1280px;
            margin: 0 auto;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
        }

        .brand { display: flex; align-items: center; gap: 11px; }
        .brand-mark {
            width: 36px; height: 36px; border-radius: 10px;
            display: grid; place-items: center;
            background: linear-gradient(135deg, #0ea5e9, #6366f1);
            color: #fff; font-weight: 800; letter-spacing: .5px;
            box-shadow: 0 6px 18px rgba(56, 189, 248, .25);
        }
        .brand h1 { margin: 0; font-size: 17px; letter-spacing: .4px; }
        .brand small { color: var(--muted); font-size: 11px; display: block; letter-spacing: .3px; }

        nav.links { display: flex; gap: 6px; margin-left: 8px; }
        nav.links a {
            color: var(--muted);
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
        }
        nav.links a:hover { color: var(--text); background: var(--panel-2); text-decoration: none; }
        nav.links a.active { color: #04121f; background: var(--accent); }

        .topbar-right {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--muted);
            font-size: 12px;
            flex-wrap: wrap;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: var(--panel-2);
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 5px 11px;
            color: var(--muted);
            font-size: 12px;
            white-space: nowrap;
        }

        .chip strong { color: var(--text); font-weight: 600; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--unknown); display: inline-block; }
        .dot.online { background: var(--ok); box-shadow: 0 0 0 3px rgba(52, 211, 153, .16); }
        .dot.degraded { background: var(--warn); box-shadow: 0 0 0 3px rgba(251, 191, 36, .16); }
        .dot.reachable, .dot.in-sync { background: var(--ok); }
        .dot.unreachable { background: var(--orange); }
        .dot.offline, .dot.failed { background: var(--bad); }
        .dot.destroyed { background: #dc2626; }
        .dot.unknown { background: var(--unknown); }

        .page-head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 18px;
            padding: 30px 0 18px;
            flex-wrap: wrap;
        }
        .page-head h2 { margin: 0; font-size: 25px; letter-spacing: -.3px; }
        .page-head p { margin: 6px 0 0; color: var(--muted); max-width: 720px; }

        .breadcrumb { color: var(--muted); font-size: 13px; margin-bottom: 8px; }
        .breadcrumb a { color: var(--muted); }
        .breadcrumb a:hover { color: var(--accent); }

        .grid { display: grid; gap: 16px; }
        .grid.cards { grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); }
        .grid.two { grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); }
        .grid.three { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }

        .card {
            background: linear-gradient(180deg, rgba(20, 35, 58, .95), rgba(15, 27, 45, .95));
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 18px;
            box-shadow: 0 12px 30px rgba(2, 8, 20, .35);
        }
        .card h3 {
            margin: 0 0 12px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: .8px;
            color: var(--muted);
            font-weight: 700;
        }
        .card .hint { color: var(--muted); font-size: 12px; margin: 10px 0 0; }

        .stat { padding: 16px 18px; }
        .stat .label {
            color: var(--muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .9px;
            font-weight: 700;
        }
        .stat .value {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -.6px;
            margin-top: 6px;
            font-variant-numeric: tabular-nums;
            overflow-wrap: anywhere;
        }
        .stat .value.small { font-size: 18px; letter-spacing: 0; }
        .stat .sub { color: var(--muted); font-size: 12px; margin-top: 4px; }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 3px 10px;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: .3px;
            text-transform: uppercase;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .badge.online, .badge.healthy, .badge.in-sync, .badge.approved, .badge.completed, .badge.self {
            background: rgba(52, 211, 153, .12); color: var(--ok); border-color: rgba(52, 211, 153, .35);
        }
        .badge.degraded, .badge.incomplete, .badge.pending, .badge.running {
            background: rgba(251, 191, 36, .12); color: var(--warn); border-color: rgba(251, 191, 36, .35);
        }
        .badge.unreachable, .badge.behind {
            background: rgba(251, 146, 60, .12); color: var(--orange); border-color: rgba(251, 146, 60, .35);
        }
        .badge.offline, .badge.failed, .badge.revoked, .badge.destroyed {
            background: rgba(248, 113, 113, .12); color: var(--bad); border-color: rgba(248, 113, 113, .35);
        }
        .badge.unknown, .badge.standalone {
            background: rgba(148, 163, 184, .12); color: var(--unknown); border-color: rgba(148, 163, 184, .3);
        }

        .table-wrap { overflow-x: auto; border-radius: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 11px 12px; border-bottom: 1px solid var(--line); white-space: nowrap; }
        th {
            color: var(--muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .8px;
            font-weight: 700;
            background: rgba(8, 17, 31, .45);
        }
        tbody tr:hover { background: rgba(56, 189, 248, .05); }
        tbody tr:last-child td { border-bottom: 0; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        td .ident { display: block; color: var(--muted); font-size: 11.5px; margin-top: 1px; }
        td .name { font-weight: 600; color: var(--text); }

        .muted { color: var(--muted); }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; }
        .nowrap { white-space: nowrap; }
        .right { text-align: right; }
        .mt { margin-top: 16px; }
        .mb { margin-bottom: 16px; }

        pre.vector {
            margin: 0;
            padding: 12px 14px;
            border-radius: 10px;
            background: rgba(4, 12, 24, .8);
            border: 1px solid var(--line);
            color: #bae6fd;
            overflow-x: auto;
            font-size: 12px;
        }

        .kv { display: grid; grid-template-columns: 150px 1fr; gap: 8px 16px; }
        .kv dt { color: var(--muted); font-size: 12px; }
        .kv dd { margin: 0; overflow-wrap: anywhere; }

        .empty {
            border: 1px dashed var(--line);
            border-radius: 10px;
            padding: 18px;
            color: var(--muted);
            text-align: center;
            font-size: 13px;
        }

        .actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

        button, .button {
            appearance: none;
            border: 1px solid rgba(56, 189, 248, .4);
            background: rgba(56, 189, 248, .12);
            color: var(--accent);
            border-radius: 9px;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
        }
        button:hover, .button:hover { background: rgba(56, 189, 248, .2); text-decoration: none; }
        button:disabled { opacity: .55; cursor: wait; }

        .flash {
            margin-top: 12px;
            border-radius: 9px;
            padding: 9px 12px;
            font-size: 12.5px;
            border: 1px solid var(--line);
            background: var(--panel-2);
            color: var(--muted);
            display: none;
        }
        .flash.show { display: block; }
        .flash.ok { border-color: rgba(52, 211, 153, .4); color: var(--ok); }
        .flash.bad { border-color: rgba(248, 113, 113, .4); color: var(--bad); }

        footer {
            margin-top: 28px;
            color: var(--muted);
            font-size: 12px;
            text-align: center;
            border-top: 1px solid var(--line);
            padding-top: 16px;
        }

        @media (max-width: 720px) {
            .shell { padding: 0 14px 48px; }
            .topbar-inner { padding: 12px 14px; }
            .kv { grid-template-columns: 1fr; gap: 2px; }
            .kv dd { margin-bottom: 8px; }
        }

        @media (max-width: 1100px) {
            .nodes-table th:nth-child(2), .nodes-table td:nth-child(2),
            .nodes-table th:nth-child(6), .nodes-table td:nth-child(6),
            .nodes-table th:nth-child(8), .nodes-table td:nth-child(8) { display: none; }
        }
    </style>
</head>
<body @yield('page-attributes')>
<header class="topbar">
    <div class="topbar-inner">
        <div class="brand">
            <div class="brand-mark">DS</div>
            <div>
                <h1>D-STORE</h1>
                <small>decentralized mesh · no database</small>
            </div>
        </div>

        <nav class="links">
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Overview</a>
            @php($selfId = $selfId ?? ($overview['node']['node_id'] ?? null))
            @if ($selfId)
                <a href="{{ route('dashboard.node', ['node' => $selfId]) }}" class="{{ request()->routeIs('dashboard.node') && request()->route('node') === $selfId ? 'active' : '' }}">This node</a>
            @endif
        </nav>

        <div class="topbar-right">
            @if (!empty($switchNodes))
                <label class="chip" style="gap:8px;">
                    <span>Switch node</span>
                    <select id="node-switcher" style="background:var(--panel-3);color:var(--text);border:1px solid var(--line);border-radius:8px;padding:4px 8px;font:inherit;max-width:220px;">
                        @foreach ($switchNodes as $n)
                            @php($nid = $n['node_id'] ?? ($n['id'] ?? ''))
                            @php($nurl = rtrim((string) ($n['url'] ?? ''), '/'))
                            @php($isCurrent = isset($selfId) && $nid === $selfId)
                            <option value="{{ $nurl !== '' ? $nurl.'/' : url('/nodes/'.$nid) }}" {{ $isCurrent ? 'selected' : '' }}>
                                {{ $n['name'] ?? $nid }}{{ $isCurrent ? ' (current)' : '' }}
                            </option>
                        @endforeach
                    </select>
                </label>
            @endif
            <span class="chip"><span class="dot online"></span> live · refresh 5 s</span>
            <span class="chip">updated <strong data-generated>—</strong></span>
        </div>
    </div>
</header>

<main class="shell">
    @yield('content')

    <footer>
        D-STORE — read-only network view over signed heartbeats, event cursors and stored health.
        Values refresh from this node's own files; nothing on this page is hard-coded.
    </footer>
</main>

<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const page = document.body.dataset.page;

    const esc = (value) => (value === null || value === undefined || value === '') ? '—' : String(value);

    function fmtNumber(value) {
        if (value === null || value === undefined || value === '') return '—';
        const n = Number(value);
        return Number.isFinite(n) ? n.toLocaleString() : esc(value);
    }

    function fmtBytes(value) {
        if (value === null || value === undefined || value === '') return '—';
        const n = Number(value);
        if (!Number.isFinite(n)) return esc(value);
        if (n < 1024) return n + ' B';
        const units = ['KB', 'MB', 'GB', 'TB'];
        let size = n / 1024;
        let i = 0;
        while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
        return size.toFixed(size >= 10 ? 0 : 1) + ' ' + units[i];
    }

    function fmtDuration(seconds) {
        if (seconds === null || seconds === undefined || seconds === '') return '—';
        const s = Math.max(0, Math.floor(Number(seconds)));
        if (!Number.isFinite(s)) return '—';
        if (s < 60) return s + ' s';
        const m = Math.floor(s / 60);
        if (m < 60) return m + 'm ' + (s % 60) + 's';
        const h = Math.floor(m / 60);
        if (h < 24) return h + 'h ' + (m % 60) + 'm';
        const d = Math.floor(h / 24);
        return d + 'd ' + (h % 24) + 'h';
    }

    function fmtTime(value) {
        if (!value) return '—';
        const d = new Date(value);
        if (Number.isNaN(d.getTime())) return esc(value);
        const pad = (x) => String(x).padStart(2, '0');
        return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()) +
            ' ' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) + ':' + pad(d.getUTCSeconds()) + ' UTC';
    }

    function ago(value) {
        if (!value) return '—';
        const d = new Date(value);
        if (Number.isNaN(d.getTime())) return esc(value);
        const s = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000));
        if (s < 60) return s + ' s ago';
        if (s < 3600) return Math.floor(s / 60) + 'm ago';
        if (s < 86400) return Math.floor(s / 3600) + 'h ago';
        return Math.floor(s / 86400) + 'd ago';
    }

    function badge(state, text) {
        const span = document.createElement('span');
        span.className = 'badge ' + String(state || 'unknown');
        span.textContent = text || String(state || 'unknown');
        return span;
    }

    function setText(el, value, format) {
        if (!el) return;
        let out;
        switch (format) {
            case 'bytes': out = fmtBytes(value); break;
            case 'duration': out = fmtDuration(value); break;
            case 'time': out = fmtTime(value); break;
            case 'ago': out = ago(value); break;
            case 'number': out = fmtNumber(value); break;
            case 'json': out = JSON.stringify(value ?? {}); break;
            case 'latency': out = (value === null || value === undefined || value === '') ? '—' : Number(value).toFixed(1) + ' ms'; break;
            default: out = esc(value);
        }
        el.textContent = out;
    }

    function setStateBadge(el, state) {
        if (!el) return;
        el.className = 'badge ' + String(state || 'unknown');
        el.textContent = String(state || 'unknown');
    }

    document.querySelectorAll('[data-generated]').forEach((el) => { el.textContent = '—'; });

    /* ------------------------------------------------------------------ */
    /* Overview                                                            */
    /* ------------------------------------------------------------------ */

    function nodeRow(node) {
        const tr = document.createElement('tr');

        const nameTd = document.createElement('td');
        const link = document.createElement('a');
        link.href = '/nodes/' + encodeURIComponent(node.node_id);
        link.className = 'name';
        link.textContent = node.name || node.node_id;
        const ident = document.createElement('span');
        ident.className = 'ident';
        ident.textContent = node.node_id + (node.role === 'self' ? ' · this node' : '');
        nameTd.append(link, ident);

        const regionTd = document.createElement('td');
        regionTd.textContent = esc(node.region);

        const trustTd = document.createElement('td');
        trustTd.appendChild(badge(node.trust, node.trust));

        const stateTd = document.createElement('td');
        stateTd.appendChild(badge(node.state, node.state));

        const latencyTd = document.createElement('td');
        latencyTd.className = 'num';
        latencyTd.textContent = node.latency_ms === null || node.latency_ms === undefined ? '—' : Number(node.latency_ms).toFixed(1) + ' ms';

        const uptimeTd = document.createElement('td');
        uptimeTd.className = 'num';
        uptimeTd.textContent = fmtDuration(node.uptime_seconds);

        const recordsTd = document.createElement('td');
        recordsTd.className = 'num';
        recordsTd.textContent = fmtNumber(node.records);

        const eventsTd = document.createElement('td');
        eventsTd.className = 'num';
        eventsTd.textContent = fmtNumber(node.events_committed);

        const storageTd = document.createElement('td');
        storageTd.className = 'num';
        storageTd.textContent = fmtBytes(node.storage_bytes);

        const seenTd = document.createElement('td');
        seenTd.textContent = node.role === 'self' ? 'now' : ago(node.last_seen_at);
        seenTd.title = node.last_seen_at || '';

        tr.append(nameTd, regionTd, trustTd, stateTd, latencyTd, uptimeTd, recordsTd, eventsTd, storageTd, seenTd);
        return tr;
    }

    function refreshOverview() {
        fetch('/dashboard/data', { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
            .then((r) => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)))
            .then((data) => {
                const summary = data.summary || {};
                document.querySelectorAll('[data-stat]').forEach((el) => {
                    const key = el.dataset.stat;
                    if (key === 'mesh') {
                        el.innerHTML = '';
                        el.appendChild(badge(summary.mesh, summary.mesh));
                        return;
                    }
                    setText(el, summary[key], el.dataset.format || 'number');
                });

                const self = data.node || {};
                document.querySelectorAll('[data-self]').forEach((el) => {
                    setText(el, self[el.dataset.self], el.dataset.format || 'text');
                });
                document.querySelectorAll('[data-self-badge]').forEach((el) => setStateBadge(el, self.state));

                const body = document.getElementById('nodes-body');
                if (body) {
                    body.innerHTML = '';
                    (data.nodes || []).forEach((node) => body.appendChild(nodeRow(node)));
                }

                document.querySelectorAll('[data-generated]').forEach((el) => { el.textContent = fmtTime(data.generated_at); });
            })
            .catch(() => {
                document.querySelectorAll('[data-generated]').forEach((el) => { el.textContent = 'unreachable'; });
            });
    }

    /* ------------------------------------------------------------------ */
    /* Node detail                                                         */
    /* ------------------------------------------------------------------ */

    function refreshNode(nodeId) {
        fetch('/dashboard/data/nodes/' + encodeURIComponent(nodeId), { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
            .then((r) => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)))
            .then((data) => {
                const node = data.node || {};

                document.querySelectorAll('[data-field]').forEach((el) => {
                    setText(el, node[el.dataset.field], el.dataset.format || 'text');
                });
                document.querySelectorAll('[data-state-badge]').forEach((el) => setStateBadge(el, node.state));
                document.querySelectorAll('[data-generated]').forEach((el) => { el.textContent = fmtTime(new Date().toISOString()); });
            })
            .catch(() => {
                document.querySelectorAll('[data-generated]').forEach((el) => { el.textContent = 'unreachable'; });
            });
    }

    /* ------------------------------------------------------------------ */
    /* Probe button                                                        */
    /* ------------------------------------------------------------------ */

    function wireProbe() {
        const button = document.getElementById('probe-button');
        if (!button) return;

        button.addEventListener('click', () => {
            const nodeId = button.dataset.node;
            const flash = document.getElementById('probe-result');
            button.disabled = true;

            fetch('/dashboard/probe/' + encodeURIComponent(nodeId), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
            })
                .then(async (r) => ({ ok: r.ok, body: await r.json().catch(() => ({})) }))
                .then(({ ok, body }) => {
                    if (flash) {
                        flash.className = 'flash show ' + (ok ? 'ok' : 'bad');
                        flash.textContent = ok
                            ? 'Heartbeat accepted in ' + Number(body.heartbeat?.response_ms || 0).toFixed(1) + ' ms.'
                            : 'Heartbeat failed: ' + (body.error || body.heartbeat?.error || 'peer did not answer');
                    }
                    refreshNode(nodeId);
                })
                .catch((error) => {
                    if (flash) {
                        flash.className = 'flash show bad';
                        flash.textContent = 'Request failed: ' + error.message;
                    }
                })
                .finally(() => { button.disabled = false; });
        });
    }

    wireProbe();
    const switcher = document.getElementById('node-switcher');
    if (switcher) { switcher.addEventListener('change', function () { if (this.value) window.location.href = this.value; }); }

    if (page === 'overview') {
        refreshOverview();
        setInterval(refreshOverview, 5000);
    }

    if (page === 'node') {
        const nodeId = document.body.dataset.node;
        if (nodeId) {
            refreshNode(nodeId);
            setInterval(() => refreshNode(nodeId), 5000);
        }
    }
})();
</script>
</body>
</html>
