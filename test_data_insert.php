<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>D-STORE Data Flow Test</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 20px; background: #08111f; color: #e6eefb; }
        .container { background: #0f1b2d; padding: 20px; border-radius: 10px; }
        h2 { color: #38bdf8; }
        input, select { padding: 8px; margin: 5px 0; }
        button { padding: 10px 20px; background: #38bdf8; color: #08111f; border: none; border-radius: 5px; cursor: pointer; }
        button:hover { background: #6366f1; }
        .result { margin: 10px 0; padding: 10px; background: #14233a; border-radius: 5px; border-left: 4px solid #38bdf8; }
        .good { border-left-color: #34d399; }
        .bad { border-left-color: #f87171; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #23374f; }
        th { color: #8ea3c0; }
    </style>
</head>
<body>
    <div class="container">
        <h2>D-STORE Data Flow Test</h2>
        
        <form id="dataForm">
            <h3>Insert Record</h3>
            <input type="text" id="recordName" placeholder="Record name (e.g., test-flow)" value="test-page-flow">
            <input type="number" id="recordValue" placeholder="Record value" value="42">
            <select id="recordCollection">
                <option value="users">users</option>
                <option value="products">products</option>
                <option value="documents">documents</option>
                <option value="prd">prd</option>
            </select>
            <button type="submit">Insert Record</button>
        </form>
        
        <div id="results"></div>
        
        <h3>Manual Commands</h3>
        <div class="result good">
            <strong>PHP Commands to run:</strong><br>
            <code>php artisan --env=pakistan dstore:record create users --payload='{"name":"test-page-flow","value":42}'</code><br>
            <code>sleep 10</code><br>
            <code>for node in pakistan uae usa uk; do echo "$node:"; php artisan --env=$node dstore:record show users test-page-flow; done</code>
        </div>
        
        <h3>API Endpoints</h3>
        <p>Health check: <code>http://127.0.0.1:8001/api/dstore/v1/health</code></p>
        <p>Nodes list: <code>php artisan --env=pakistan dstore:node:list</code></p>
        <p>Sync status: <code>php artisan --env=pakistan dstore:sync:status</code></p>
    </form>

    <script>
        document.getElementById('dataForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const name = document.getElementById('recordName').value;
            const value = document.getElementById('recordValue').value;
            const collection = document.getElementById('recordCollection').value;
            
            const resultsDiv = document.getElementById('results');
            resultsDiv.innerHTML = '<div class="result loading">Inserting record... please wait...</div>';
            
            // Submit to Pakistan node
            fetch('/api/dstore/v1/events/push', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    eventId: 'page-test-' + Date.now(),
                    origin: 'pakistan',
                    collection: collection,
                    recordId: 'page-test-' + Date.now(),
                    data: { name, value },
                    version: [],
                    timestamp: new Date().toISOString()
                })
            })
            .then(response => response.json())
            .then(data => {
                resultsDiv.innerHTML = '<div class="result bad">Error: ' + (data.error || 'Unknown error') + '</div>';
            })
            .catch(error => {
                resultsDiv.innerHTML = '<div class="result bad">Request failed: ' + error.message + '</div>';
            });
            
            // Also run the artisan command via PHP
            setTimeout(() => {
                // Check all nodes after 8 seconds
                checkAllNodes(collection, name, value);
            }, 8000);
        });
        
        function checkAllNodes(collection, name, value) {
            resultsDiv.innerHTML += '<div class="result">Checking replication across all nodes...</div>';
            
            const nodes = ['pakistan', 'uae', 'usa', 'uk'];
            let completed = 0;
            
            nodes.forEach(node => {
                fetch(`http://127.0.0.1:800${nodes.indexOf(node)+1}/api/dstore/v1/record/${collection}/page-test-${Date.now()}`, {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' }
                })
                .then(response => response.json())
                .then(data => {
                    completed++;
                    const nodeResult = `<div class="result ${data.ok ? 'good' : 'bad'}">
                        <strong>${node}:</strong> ${data.ok ? 'Found' : 'Not found'} - ${data.name || 'N/A'}
                    </div>`;
                    resultsDiv.innerHTML += nodeResult;
                    
                    if (completed === nodes.length) {
                        resultsDiv.innerHTML += '<div class="result good">✅ All nodes checked!</div>';
                    }
                })
                .catch(() => {
                    completed++;
                    resultsDiv.innerHTML += '<div class="result bad">' + node + ': API error</div>';
                    if (completed === nodes.length) {
                        resultsDiv.innerHTML += '<div class="result bad">❌ Not all nodes responded</div>';
                    }
                });
            });
        }
    </script>
</body>
</html>