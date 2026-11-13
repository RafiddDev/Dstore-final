<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>D-STORE Data Entry</title>
    <style>
        :root { --bg:#08111f; --panel:#0f1b2d; --panel2:#14233a; --line:#23374f; --text:#e6eefb; --muted:#8ea3c0; --accent:#38bdf8; --ok:#34d399; --bad:#f87171; }
        *{box-sizing:border-box} body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--text)}
        .wrap{max-width:1200px;margin:0 auto;padding:24px}
        h1{margin:0 0 6px;font-size:28px} p.sub{color:var(--muted);margin:0 0 20px}
        .grid{display:grid;grid-template-columns:360px 1fr;gap:18px}
        .card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:16px}
        .card h2{margin:0 0 12px;font-size:16px;color:var(--accent)}
        label{display:block;font-size:13px;color:var(--muted);margin:10px 0 6px}
        input,select{width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--line);background:var(--panel2);color:var(--text)}
        button{margin-top:14px;width:100%;padding:11px 14px;border:0;border-radius:8px;background:var(--accent);color:#04121f;font-weight:700;cursor:pointer}
        button:hover{filter:brightness(1.05)} button:disabled{opacity:.6;cursor:wait}
        .msg{margin-top:12px;padding:10px 12px;border-radius:8px;border:1px solid var(--line);background:var(--panel2);font-size:13px}
        .msg.ok{border-color:rgba(52,211,153,.4);color:var(--ok)} .msg.bad{border-color:rgba(248,113,113,.4);color:var(--bad)}
        .nodes{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}
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
        .top-actions{margin-left:auto;display:flex;gap:8px}
        .small-btn{width:auto;margin:0;padding:7px 10px;font-size:12px;background:#1a2c47;color:var(--text);border:1px solid var(--line)}
        @media(max-width:900px){.grid{grid-template-columns:1fr}}
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
            <button class="small-btn" type="button" onclick="loadAll()">Refresh all</button>
            <a class="small-btn" href="/" style="text-decoration:none;display:inline-block;text-align:center">Dashboard</a>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Insert Data</h2>
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
                <input id="password" name="password" type="text" placeholder="password" required>

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
                    <td class="mono">${esc(d.password)}</td>
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

async function loadNode(node){
    const url = (node.url || '').replace(/\/$/, '') + '/data-entry/records';
    try {
        const r = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
        const data = await r.json().catch(()=>({ok:false,error:'invalid json'}));
        if(!r.ok) return { ok:false, error: 'HTTP '+r.status };
        return data;
    } catch(e){ return { ok:false, error: e.message }; }
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
        const r = await fetch(targetUrl + '/data-entry', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
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

loadAll();
setInterval(loadAll, 12000);
</script>
</body>
</html>
