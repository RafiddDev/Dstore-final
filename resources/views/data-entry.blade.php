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
            <p class="sub">Kisi bhi node par record insert karo (username) aur sab nodes par replication verify karo.</p>
            @if(!empty($setupMode))
                <div class="msg" style="margin-top:8px;border-color:rgba(56,189,248,.4);color:var(--accent)">One-time setup link active: sirf <strong>name</strong> dal kar node add karein. Ye option ek baar use hone ke baad band ho jayega.</div>
            @endif
        </div>
        <div class="top-actions">
            @if(!empty($setupMode))
                <button class="small-btn btn-accent" id="addNodeBtn" type="button" onclick="openAddNode()">+ Add node (one-time)</button>
            @endif
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
                            {{ $n['name'] }} - {{ $n['url'] }}
                        </option>
                    @endforeach
                </select>

                <label for="username">Username</label>
                <input id="username" name="username" type="text" placeholder="username" required>

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

@if(!empty($setupMode))
<div id="addNodeModal" class="modal-backdrop" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="addNodeTitle">
        <h3 id="addNodeTitle">Add New Node (One-time)</h3>
        <p>Sirf <strong>node ka naam</strong> likhein — ID, port aur URL auto-generate honge. Ye setup link <strong>sirf ek baar</strong> kaam karega; node add hote hi band ho jayega.</p>
        <form id="addNodeForm">
            <div class="modal-grid">
                <div class="full">
                    <label for="an_name">Node Name</label>
                    <input id="an_name" name="name" type="text" placeholder="PC 3" required>
                </div>
                <input type="hidden" id="an_id" name="id" value="">
                <input type="hidden" id="an_region" name="region" value="">
                <input type="hidden" id="an_port" name="port" value="">
                <input type="hidden" id="an_url" name="url" value="">
            </div>
            <div id="addNodeAuto" class="note-box" style="display:none;margin-top:10px"></div>
            <div id="addNodeMsg" class="msg" style="display:none"></div>
            <div id="addNodeNext" class="note-box" style="display:none"></div>
            <div class="modal-actions">
                <button type="button" class="small-btn btn-secondary" onclick="closeAddNode()">Cancel</button>
                <button type="submit" class="small-btn btn-accent" id="addNodeSubmit">Create Node</button>
            </div>
        </form>
    </div>
</div>
@endif

<script>
const NODES = @json(array_values($nodes));
const CURRENT = @json($current);
const SETUP_MODE = @json((bool)($setupMode ?? false));
const SETUP_TOKEN = @json((string)($setupToken ?? ''));

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
        rows = `<div style="overflow:auto"><table><thead><tr><th>ID</th><th>Username</th><th>Writer</th></tr></thead><tbody>` +
            records.slice(0,20).map(r=>{
                const d=r.data||{}; return `<tr>
                    <td class="mono">${esc(r.id)}</td>
                    <td>${esc(d.username)}</td>
                    <td>${esc(r.writer||'')}</td>
                </tr>`;
            }).join('') + `</tbody></table></div>`;
        if (records.length>20) rows += `<div class="muted" style="margin-top:6px">+ ${records.length-20} more...</div>`;
    }
    return `<div class="card node-card">
        <h3><span>${esc(node.name)}</span>
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

document.getElementById('entryForm').addEventListener('submit', async (e)=>{
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    const targetUrl = document.getElementById('targetNode').value.replace(/\/$/, '');
    const targetId = document.getElementById('targetNode').selectedOptions[0]?.dataset.id || '';
    const payload = {
        username: document.getElementById('username').value,
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

if (SETUP_MODE) {
  function openAddNode(){
    const m = document.getElementById('addNodeModal');
    const name = document.getElementById('an_name');
    const id = document.getElementById('an_id');
    const url = document.getElementById('an_url');
    const port = document.getElementById('an_port');
    const region = document.getElementById('an_region');
    const auto = document.getElementById('addNodeAuto');
    const msg = document.getElementById('addNodeMsg');
    const next = document.getElementById('addNodeNext');
    if(!m) return;
    msg.style.display='none'; next.style.display='none'; next.textContent='';
    auto.style.display='none'; auto.textContent='';
    m.classList.add('show'); m.setAttribute('aria-hidden','false');
    setTimeout(()=>name && name.focus(), 30);

    const syncAuto = () => {
      const slug = (name.value || '').toLowerCase().trim().replace(/[^a-z0-9_-]+/g,'-').replace(/^-+|-+$/g,'') || 'node';
      id.value = slug;
      let maxPort = 8004;
      (NODES || []).forEach(n => { const p = parseInt(((n.url||'').split(':')[2])||'0',10) || 0; if (p > maxPort) maxPort = p; });
      const p = maxPort + 1 < 8005 ? 8005 : maxPort + 1;
      port.value = p;
      url.value = 'http://' + window.location.hostname + ':' + p; // best-effort; server may refine
      region.value = '';
      auto.style.display='block';
      auto.textContent = 'Auto: ID=' + slug + ' | Port=' + p + ' | URL=' + url.value + ' (server final kar sakta hai)';
    };
    name.oninput = syncAuto;
    syncAuto();
  }
  function closeAddNode(){
    const m = document.getElementById('addNodeModal');
    if (m) { m.classList.remove('show'); m.setAttribute('aria-hidden','true'); }
  }
  const modal = document.getElementById('addNodeModal');
  if (modal) modal.addEventListener('click', function(e){ if(e.target===this) closeAddNode(); });

  const addNodeForm = document.getElementById('addNodeForm');
  if (addNodeForm) addNodeForm.addEventListener('submit', async function(e){
    e.preventDefault();
    const btn = document.getElementById('addNodeSubmit');
    const msg = document.getElementById('addNodeMsg');
    const next = document.getElementById('addNodeNext');
    const payload = {
      name: document.getElementById('an_name').value.trim(),
      id: document.getElementById('an_id').value.trim(),
      region: document.getElementById('an_region').value.trim(),
      url: document.getElementById('an_url').value.trim(),
      port: document.getElementById('an_port').value ? Number(document.getElementById('an_port').value) : null,
      setup_token: SETUP_TOKEN
    };
    btn.disabled = true;
    msg.style.display='block'; msg.className='msg'; msg.textContent='Node add ho raha hai (one-time setup)...';
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
      msg.textContent = (data.message || 'Node created.') + ' (id: ' + (data.node?.node_id || data.node?.id || payload.id) + ')';
      const steps = Array.isArray(data.next_steps) ? ('\n\nNext steps (manual):\n- ' + data.next_steps.join('\n- ')) : '';
      next.style.display='block';
      next.textContent = 'Dashboard (isolated): ' + (data.dashboard_url || ('/dashboard/isolated/' + (data.node?.node_id || payload.id)))
        + '\nStorage: ' + (data.storage_path || ('nodes/'+(data.node?.node_id||payload.id)+'/storage/dstore'))
        + '\nPublic key: ' + (data.node?.public_key || '-')
        + '\nFingerprint: ' + (data.node?.fingerprint || '-')
        + '\n\nOne-time setup link ab use ho chuki hai. Is page ko refresh karne par Add node option nahi rahega.'
        + steps;
      const addBtn = document.getElementById('addNodeBtn'); if (addBtn) addBtn.style.display = 'none';
    } catch(err){
      msg.className='msg bad';
      msg.textContent = 'Add node failed: ' + err.message;
    } finally {
      btn.disabled = false;
    }
  });
}

loadAll();
loadSyncHistory();
setInterval(loadAll, 12000);
setInterval(loadSyncHistory, 10000);
</script>
</body>
</html>
