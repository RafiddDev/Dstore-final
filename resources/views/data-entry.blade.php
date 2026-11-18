<!DOCTYPE html>
<html lang="en">
    <meta name="csrf-token" content="{{ csrf_token() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>D-STORE Data Entry</title>
    <style>
        :root { --bg:#08111f; --panel:#0f1b2d; --panel2:#14233a; --line:#23374f; --text:#e6eefb; --muted:#8ea3c0; --accent:#38bdf8; --ok:#34d399; --bad:#f87171; }
        *{box-sizing:border-box} body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--text)}
        .wrap{max-width:1200px;margin:0 auto;padding:24px}
        h1{margin:0 0 6px;font-size:28px} p.sub{color:var(--muted);margin:0 0 20px}
        .grid{display:grid;grid-template-columns:360px 1fr 320px;gap:18px;align-items:start}
        .card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:16px}
        .history-list{max-height:70vh;overflow:auto;display:flex;flex-direction:column;gap:10px}
        .hist-item{border:1px solid var(--line);background:var(--panel2);border-radius:10px;padding:10px 12px;font-size:12.5px}
        .hist-top{display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:4px}
        .pill{font-size:11px;padding:2px 8px;border-radius:999px;border:1px solid var(--line);color:var(--muted)}
        .pill.manual{color:var(--accent);border-color:rgba(56,189,248,.4)} .pill.auto{color:#a78bfa;border-color:rgba(167,139,250,.4)}
        .pill.ok{color:var(--ok);border-color:rgba(52,211,153,.4)} .pill.bad{color:var(--bad);border-color:rgba(248,113,113,.4)}
        .card h2{margin:0 0 12px;font-size:16px;color:var(--accent)}
        label{display:block;font-size:13px;color:var(--muted);margin:10px 0 6px}
        input,select{width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--line);background:var(--panel2);color:var(--text)}
        .pw-wrap{position:relative}
        .pw-wrap input{padding-right:42px}
        .pw-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:28px;height:28px;margin:0;padding:0;border:0;border-radius:6px;background:transparent;color:var(--muted);cursor:pointer;display:flex;align-items:center;justify-content:center}
        .pw-toggle:hover{color:var(--text);background:rgba(255,255,255,.05);filter:none}
        .pw-toggle svg{width:18px;height:18px;fill:currentColor}
        button{margin-top:14px;width:100%;padding:11px 14px;border:0;border-radius:8px;background:var(--accent);color:#04121f;font-weight:700;cursor:pointer}
        button:hover{filter:brightness(1.05)} button:disabled{opacity:.6;cursor:wait}
        .msg{margin-top:12px;padding:10px 12px;border-radius:8px;border:1px solid var(--line);background:var(--panel2);font-size:13px}
        .msg.ok{border-color:rgba(52,211,153,.4);color:var(--ok)} .msg.bad{border-color:rgba(248,113,113,.4);color:var(--bad)}
        .nodes{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
        .node-card h3{margin:0 0 4px;font-size:15px;display:flex;justify-content:space-between;align-items:center}
        .badge{font-size:11px;padding:3px 8px;border-radius:999px;border:1px solid var(--line);color:var(--muted)}
        .badge.ok{color:var(--ok);border-color:rgba(52,211,153,.4)}
        .meta{color:var(--muted);font-size:12px;margin-bottom:10px}
        table{width:100%;border-collapse:collapse;font-size:12.5px}
        th,td{padding:8px 6px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
        th{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.5px}
        .mono{font-family:ui-monospace,Consolas,monospace}
        .muted{color:var(--muted)} .empty{color:var(--muted);font-size:13px;padding:8px 0}
        .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
        .top-actions{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}
        .small-btn{width:auto;margin:0;padding:7px 10px;font-size:12px;background:#1a2c47;color:var(--text);border:1px solid var(--line)}
        .modal-backdrop{position:fixed;inset:0;background:rgba(2,8,20,.72);display:none;align-items:center;justify-content:center;padding:18px;z-index:60}
        .modal-backdrop.show{display:flex}
        .modal{width:100%;max-width:560px;background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:18px;box-shadow:0 20px 50px rgba(0,0,0,.45)}
        .modal h3{margin:0 0 6px;font-size:18px;color:var(--accent)}
        .modal p{margin:0 0 12px;color:var(--muted);font-size:13px}
        .modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 12px}
        .modal-grid .full{grid-column:1 / -1}
        .modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:14px}
        .btn-secondary{background:#1a2c47;color:var(--text);border:1px solid var(--line)}
        .btn-accent{background:var(--accent);color:#04121f}
        .note-box{margin-top:10px;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--panel2);font-size:12.5px;color:var(--muted);white-space:pre-wrap}
        @media(max-width:1100px){.grid{grid-template-columns:1fr} .nodes{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="row" style="margin-bottom:16px">
        <div>
            <h1>D-STORE Data Entry</h1>
            <p class="sub">Kisi bhi node par record insert karo (email, username, password) aur sab nodes par replication verify karo.</p>
        </div>
        <div class="top-actions">
            <button class="small-btn" id="addNodeBtn" type="button" onclick="openAddNode()">+ Add node</button>
            <button class="small-btn" id="syncBtn" type="button" onclick="syncNow()">Sync now</button>
            <button class="small-btn" type="button" onclick="loadAll()">Refresh all</button>
            <a class="small-btn" href="/" style="text-decoration:none;display:inline-block;text-align:center">Dashboard</a>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Insert Data</h2>
            <div id="syncMsg" class="msg" style="display:none;margin-top:0;margin-bottom:12px"></div>
            <form id="entryForm">
                <label for="targetNode">Target Node (jis node par save hoga)</label>
                <select id="targetNode" name="target_node" required>
                    @foreach($nodes as $n)
                        <option value="{{ $n['url'] }}" data-id="{{ $n['id'] }}" @selected($n['id']===$current['id'])>
                            {{ $n['name'] }} ({{ $n['id'] }}) - {{ $n['url'] }}
                        </option>
                    @endforeach
                </select>

                <label for="email">Email</label>
                <input id="email" name="email" type="email" placeholder="user@example.com" required>

                <label for="username">Username</label>
                <input id="username" name="username" type="text" placeholder="username" required>

                <label for="password">Password</label>
                <div class="pw-wrap">
                    <input id="password" name="password" type="password" placeholder="password" required>
                    <button type="button" class="pw-toggle" id="togglePassword" aria-label="Show password" title="Show password">
                        <svg id="eyeIcon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c5.05 0 9.27 3.11 11 7-1.73 3.89-5.95 7-11 7S2.73 15.89 1 12c1.73-3.89 5.95-7 11-7zm0 2C8.24 7 4.94 9.24 3.35 12 4.94 14.76 8.24 17 12 17s7.06-2.24 8.65-5C19.06 9.24 15.76 7 12 7zm0 2.5A2.5 2.5 0 1 1 9.5 12 2.5 2.5 0 0 1 12 9.5z"/></svg>
                        <svg id="eyeOffIcon" viewBox="0 0 24 24" aria-hidden="true" style="display:none"><path d="M3.28 2.22 2.22 3.28l3.1 3.1C3.3 7.61 1.78 9.6 1 12c1.73 3.89 5.95 7 11 7 2.01 0 3.89-.49 5.55-1.36l3.17 3.17 1.06-1.06L3.28 2.22zM12 17c-3.76 0-7.06-2.24-8.65-5 .74-1.28 1.9-2.45 3.33-3.33l1.7 1.7A4.5 4.5 0 0 0 12 16.5c.49 0 .96-.08 1.4-.22l1.73 1.73c-1 .64-2.06.99-3.13.99zm0-10c3.76 0 7.06 2.24 8.65 5-.51.89-1.2 1.72-2.04 2.46l-1.43-1.43c.2-.48.32-1 .32-1.53A5.5 5.5 0 0 0 12 6.5c-.53 0-1.05.12-1.53.32L8.86 5.21C9.82 4.43 10.87 4 12 4v3zm0 4.5c.1 0 .2.01.29.03l-2.76-2.76c.02.09.03.19.03.29A2.44 2.44 0 0 0 12 8.5zm4.94 5.28-1.5-1.5A4.47 4.47 0 0 0 16.5 10.5c0-.24-.02-.47-.06-.7l1.73 1.73c-.34.8-.77 1.55-1.23 2.25z"/></svg>
                    </button>
                </div>

                <button id="submitBtn" type="submit">Save & Replicate</button>
            </form>
            <div id="formMsg" class="msg" style="display:none"></div>
            <div class="msg" style="margin-top:12px">
                <strong>Current page node:</strong> {{ $current['name'] }} ({{ $current['id'] }})<br>
                <span class="muted">Form selected node par POST karega. Save ke baad sab nodes ki list auto-refresh hogi (sync ke liye ~8-10s lag sakta hai).</span>
            </div>
        </div>

        <div class="card">
            <h2>Records on All Nodes</h2>
            <p class="muted" style="margin-top:-4px">Har node ki local `accounts/users` records neeche dikh rahi hain. Replication sahi ho to naya record sab mein aa jayega.</p>
            <div id="nodesWrap" class="nodes"></div>
        </div>

        <div class="card">
            <h2>Sync History <span id="historyNodeLabel" class="muted" style="font-size:12px;font-weight:500"></span></h2>
            <p class="muted" style="margin-top:-4px">Selected target node ki manual aur auto sync history (har sync run ka time aur result).</p>
            <div id="syncHistoryWrap" class="history-list"><div class="empty">Loading history...</div></div>
        </div>
    </div>
</div>

<div id="addNodeModal" class="modal-backdrop" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="addNodeTitle">
        <h3 id="addNodeTitle">Add New Node</h3>
        <p>Naya node scaffold karo (identity/keys + storage + peer registration). Ye node Target Node, Sync History aur Dashboard mein turant show ho jayega. Isay chalane/connect karne ke liye new PC par run karna hoga.</p>
        <form id="addNodeForm">
            <div class="modal-grid">
                <div>
                    <label for="an_name">Node Name</label>
                    <input id="an_name" name="name" type="text" placeholder="Dubai" required>
                </div>
                <div>
                    <label for="an_id">Node ID (unique)</label>
                    <input id="an_id" name="id" type="text" placeholder="dubai" required pattern="[a-zA-Z0-9][a-zA-Z0-9_-]*">
                </div>
                <div>
                    <label for="an_region">Region</label>
                    <input id="an_region" name="region" type="text" placeholder="middle-east">
                </div>
                <div>
                    <label for="an_port">Port (optional)</label>
                    <input id="an_port" name="port" type="number" min="1" max="65535" placeholder="8005">
                </div>
                <div class="full">
                    <label for="an_url">Public URL (reachable address of this node)</label>
                    <input id="an_url" name="url" type="text" placeholder="http://100.x.y.z:8005" required>
                </div>
            </div>
            <div id="addNodeMsg" class="msg" style="display:none"></div>
            <div id="addNodeNext" class="note-box" style="display:none"></div>
            <div class="modal-actions">
                <button type="button" class="small-btn btn-secondary" onclick="closeAddNode()">Cancel</button>
                <button type="submit" class="small-btn btn-accent" id="addNodeSubmit">Create Node</button>
            </div>
        </form>
    </div>
</div>

<script>
const NODES = @json(array_values($nodes));

function esc(v){ if(v===null||v===undefined) return '—'; return String(v).replace(/[&<>"']/g, s=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[s])); }

function nodeCard(node, state){
    const records = (state && state.records) || [];
    const ok = !!(state && state.ok);
    let rows = '';
    if (!ok) {
        rows = `<div class="empty">Node reachable nahi / error: ${esc(state && state.error || 'unknown')}</div>`;
    } else if (records.length === 0) {
        rows = `<div class="empty">Koi records nahi</div>`;
    } else {
        rows = `<div style="overflow:auto"><table><thead><tr><th>ID</th><th>Email</th><th>Username</th><th>Password</th><th>Writer</th></tr></thead><tbody>` +
            records.slice(0,20).map(r=>{
                const d=r.data||{}; return `<tr>
                    <td class="mono">${esc(r.id)}</td>
                    <td>${esc(d.email)}</td>
                    <td>${esc(d.username)}</td>
                    <td class="mono">••••••••</td>
                    <td>${esc(r.writer||'')}</td>
                </tr>`;
            }).join('') + `</tbody></table></div>`;
        if (records.length>20) rows += `<div class="muted" style="margin-top:6px">+ ${records.length-20} more...</div>`;
    }
    return `<div class="card node-card">
        <h3><span>${esc(node.name)} <span class="muted">(${esc(node.id)})</span></span>
            <span class="badge ${ok?'ok':''}">${ok? 'online' : 'error'}</span></h3>
        <div class="meta">${esc(node.url)} · records: ${ok? records.length : '—'}</div>
        ${rows}
    </div>`;
}



async function loadNode(node) {
    const url = window.location.origin +
        '/data-entry/nodes/' +
        encodeURIComponent(node.id) +
        '/records';

    try {
        const r = await fetch(url, {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            },
            credentials: 'same-origin',
            cache: 'no-store'
        });

        const data = await r.json();

        if (!r.ok) {
            return {
                ok: false,
                error: data.error || 'HTTP ' + r.status
            };
        }

        return data;

    } catch (e) {
        return {
            ok: false,
            error: e.message
        };
    }
}




async function loadAll(){
    const wrap = document.getElementById('nodesWrap');
    wrap.innerHTML = '<div class="muted">Loading from all nodes...</div>';
    const results = await Promise.all(NODES.map(async n => ({node:n, state: await loadNode(n)})));
    wrap.innerHTML = results.map(x => nodeCard(x.node, x.state)).join('');
}

function showMsg(text, ok){
    const el = document.getElementById('formMsg');
    el.style.display='block';
    el.className='msg ' + (ok?'ok':'bad');
    el.textContent=text;
}

function showSyncMsg(text, ok){
    const el = document.getElementById('syncMsg');
    el.style.display='block';
    el.className='msg ' + (ok?'ok':'bad');
    el.textContent=text;
}

function selectedTargetNodeId(){
    const sel = document.getElementById('targetNode');
    return (sel && sel.selectedOptions[0] && sel.selectedOptions[0].dataset.id) || '';
}

function selectedTargetNodeName(){
    const sel = document.getElementById('targetNode');
    const id = selectedTargetNodeId();
    const txt = (sel && sel.selectedOptions[0] && sel.selectedOptions[0].text) || id;
    return txt.split(' - ')[0] || id || 'node';
}

async function loadSyncHistory(){
    const wrap = document.getElementById('syncHistoryWrap');
    const nodeId = selectedTargetNodeId();
    const label = document.getElementById('historyNodeLabel');
    if(label) label.textContent = nodeId ? ('— ' + selectedTargetNodeName()) : '';
    if(!nodeId){
        wrap.innerHTML = '<div class="empty">Target node select karein.</div>';
        return;
    }
    try {
        const r = await fetch('/data-entry/nodes/' + encodeURIComponent(nodeId) + '/sync-history', { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
        const data = await r.json().catch(()=>({ok:false}));
        if(!r.ok || !data.ok){ throw new Error(data.error || ('HTTP '+r.status)); }
        const items = data.history || [];
        if(items.length === 0){
            wrap.innerHTML = `<div class="empty">${esc(selectedTargetNodeName())} ke liye abhi koi sync history nahi. "Sync now" chalayein ya auto sync ka wait karein.</div>`;
            return;
        }
        wrap.innerHTML = items.map(h=>{
            const type = esc(h.type || 'sync');
            const ok = h.reachable !== false && !h.error;
            const dur = (h.duration_ms !== undefined && h.duration_ms !== null) ? (Number(h.duration_ms).toFixed(2) + ' ms') : '?';
            const at = esc(h.at || '');
            const peer = esc(h.peer || '');
            const err = h.error ? `<div class="muted" style="color:var(--bad);margin-top:4px">Error: ${esc(h.error)}</div>` : '';
            return `<div class="hist-item">
                <div class="hist-top">
                    <span class="pill ${type==='manual'?'manual':'auto'}">${type}</span>
                    <span class="pill ${ok?'ok':'bad'}">${ok?'ok':'fail'}</span>
                </div>
                <div><strong>Peer:</strong> ${peer} &nbsp; <strong>Time:</strong> ${dur}</div>
                <div class="muted" style="margin-top:2px">pulled ${esc(h.pulled||0)} · applied ${esc(h.applied||0)} · pushed ${esc(h.pushed||0)} · delivered ${esc(h.delivered||0)} · pending ${esc(h.pending||0)}</div>
                <div class="muted" style="margin-top:2px">${at}</div>
                ${err}
            </div>`;
        }).join('');
    } catch(e){
        wrap.innerHTML = `<div class="empty">History load nahi ho saki: ${esc(e.message)}</div>`;
    }
}

async function syncNow(){
    const btn = document.getElementById('syncBtn');
    const nodeId = selectedTargetNodeId();
    if(!nodeId){ showSyncMsg('Target node select karein.', false); return; }
    btn.disabled = true;
    showSyncMsg('Sync chal rahi hai (' + selectedTargetNodeName() + ')...', true);
    try {
        const r = await fetch('/data-entry/nodes/' + encodeURIComponent(nodeId) + '/sync', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: '{}'
        });
        const data = await r.json().catch(()=>({ok:false,error:'invalid response'}));
        if(!r.ok || !data.ok){
            throw new Error(data.error || ('HTTP '+r.status));
        }
        const dur = (data.duration_ms !== undefined) ? (' in ' + Number(data.duration_ms).toFixed(2) + ' ms') : '';
        const msg = 'Sync complete on ' + selectedTargetNodeName() + dur + ': peers=' + (data.peers||0)
            + ', reachable=' + (data.reachable||0)
            + ', pulled=' + (data.pulled||0)
            + ', applied=' + (data.applied||0)
            + ', pushed=' + (data.pushed||0)
            + ', pending=' + (data.pending||0);
        showSyncMsg(msg, true);
        loadSyncHistory();
        setTimeout(loadAll, 500);
        setTimeout(loadAll, 2000);
    } catch(err){
        showSyncMsg('Manual sync failed: ' + err.message, false);
        loadSyncHistory();
    } finally {
        btn.disabled = false;
    }
}

document.getElementById('togglePassword').addEventListener('click', function(){
    const input = document.getElementById('password');
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    this.setAttribute('title', show ? 'Hide password' : 'Show password');
    document.getElementById('eyeIcon').style.display = show ? 'none' : '';
    document.getElementById('eyeOffIcon').style.display = show ? '' : 'none';
});

document.getElementById('entryForm').addEventListener('submit', async (e)=>{
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    const targetUrl = document.getElementById('targetNode').value.replace(/\/$/, '');
    const targetId = document.getElementById('targetNode').selectedOptions[0]?.dataset.id || '';
    const payload = {
        email: document.getElementById('email').value,
        username: document.getElementById('username').value,
        password: document.getElementById('password').value,
        collection: 'accounts'
    };
    btn.disabled = true;
    showMsg('Saving on ' + targetId + '...', true);
    try {
        const r = await fetch('/data-entry/nodes/' + encodeURIComponent(targetId), {
            method: 'POST',
            headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'X-CSRF-TOKEN': document.querySelector(
        'meta[name="csrf-token"]'
    ).content
},
            body: JSON.stringify(payload)
        });
        const data = await r.json().catch(()=>({ok:false,error:'invalid response'}));
        if(!r.ok || !data.ok){ throw new Error(data.error || ('HTTP '+r.status)); }
        showMsg('Saved on ' + targetId + ' (id: ' + (data.record?.id||'') + '). Sync ke baad sab nodes par dikhega — refreshing...', true);
        document.getElementById('entryForm').reset();
        setTimeout(loadAll, 1500);
        setTimeout(loadAll, 5000);
        setTimeout(loadAll, 10000);
    } catch(err){
        showMsg('Save failed: ' + err.message, false);
    } finally {
        btn.disabled = false;
    }
});

document.getElementById('targetNode').addEventListener('change', loadSyncHistory);

function openAddNode(){
    const m = document.getElementById('addNodeModal');
    const name = document.getElementById('an_name');
    const id = document.getElementById('an_id');
    const url = document.getElementById('an_url');
    const port = document.getElementById('an_port');
    const msg = document.getElementById('addNodeMsg');
    const next = document.getElementById('addNodeNext');
    msg.style.display='none'; next.style.display='none'; next.textContent='';
    m.classList.add('show'); m.setAttribute('aria-hidden','false');
    setTimeout(()=>name.focus(), 30);

    const syncId = () => {
        if (!id.dataset.touched) {
            id.value = (name.value || '').toLowerCase().trim().replace(/[^a-z0-9_-]+/g,'-').replace(/^-+|-+$/g,'');
        }
        if (!url.dataset.touched) {
            const nid = id.value || 'node';
            const p = port.value || '8005';
            url.value = 'http://127.0.0.1:' + p;
            url.placeholder = 'http://100.x.y.z:' + p + ' (Tailscale/real URL)';
        }
    };
    name.oninput = syncId; id.oninput = ()=>{ id.dataset.touched='1'; }; port.oninput = syncId; url.oninput = ()=>{ url.dataset.touched='1'; };
}
function closeAddNode(){
    const m = document.getElementById('addNodeModal');
    m.classList.remove('show'); m.setAttribute('aria-hidden','true');
}
document.getElementById('addNodeModal').addEventListener('click', function(e){ if(e.target===this) closeAddNode(); });

document.getElementById('addNodeForm').addEventListener('submit', async function(e){
    e.preventDefault();
    const btn = document.getElementById('addNodeSubmit');
    const msg = document.getElementById('addNodeMsg');
    const next = document.getElementById('addNodeNext');
    const payload = {
        name: document.getElementById('an_name').value.trim(),
        id: document.getElementById('an_id').value.trim(),
        region: document.getElementById('an_region').value.trim(),
        url: document.getElementById('an_url').value.trim(),
        port: document.getElementById('an_port').value ? Number(document.getElementById('an_port').value) : null
    };
    btn.disabled = true;
    msg.style.display='block'; msg.className='msg'; msg.textContent='Node scaffold ho raha hai...';
    next.style.display='none';
    try {
        const r = await fetch('/data-entry/add-node', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify(payload)
        });
        const data = await r.json().catch(()=>({ok:false,error:'invalid response'}));
        if(!r.ok || !data.ok){
            const detail = data.details ? ('\n' + JSON.stringify(data.details)) : '';
            throw new Error((data.error || ('HTTP '+r.status)) + detail);
        }
        msg.className='msg ok';
        msg.textContent = (data.message || 'Node created.') + ' (id: ' + (data.node?.id||payload.id) + ')';
        const steps = Array.isArray(data.next_steps) ? ('\n\nNext steps (manual):\n- ' + data.next_steps.join('\n- ')) : '';
        next.style.display='block';
        next.textContent = 'Storage: ' + (data.storage_path || ('nodes/'+payload.id+'/storage/dstore'))
            + '\nRegistered in current mesh metadata: ' + (data.registered_in || 'current node')
            + '\nFingerprint: ' + (data.node?.fingerprint || '—')
            + steps
            + '\n\nPage ab reload hogi taake Target Node / Dashboard / Sync history mein naya node dikh jaye.';
        // add to dropdown immediately
        const sel = document.getElementById('targetNode');
        if (sel && !Array.from(sel.options).some(o=>o.dataset.id===payload.id)) {
            const opt = document.createElement('option');
            opt.value = payload.url;
            opt.dataset.id = payload.id;
            opt.textContent = payload.name + ' (' + payload.id + ') - ' + payload.url;
            sel.appendChild(opt);
        }
        setTimeout(()=>location.reload(), 2500);
    } catch(err){
        msg.className='msg bad';
        msg.textContent = 'Add node failed: ' + err.message;
    } finally {
        btn.disabled = false;
    }
});

loadAll();
loadSyncHistory();
setInterval(loadAll, 12000);
setInterval(loadSyncHistory, 10000);
</script>
</body>
</html>
