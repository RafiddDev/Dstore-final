<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>D-STORE Data Flow Test</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 20px; background: #08111f; color: #e6eefb; }
        .container { background: #0f1b2d; padding: 20px; border-radius: 10px; }
        h2 { color: #38bdf8; margin-bottom: 20px; }
        .form-group { margin: 15px 0; }
        input, select { width: 100%; padding: 10px; margin: 5px 0; background: #14233a; color: #e6eefb; border: 1px solid #23374f; border-radius: 5px; }
        button { width: 100%; padding: 12px; background: #38bdf8; color: #08111f; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; margin-top: 10px; }
        button:hover { background: #6366f1; }
        .result { padding: 15px; margin: 10px 0; background: #14233a; border-radius: 5px; border-left: 4px solid; display: none; }
        .good { border-left-color: #34d399; }
        .bad { border-left-color: #f87171; }
        .info { border-left-color: #fbbf24; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; display: none; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #23374f; }
        th { color: #8ea3c0; background: #0f1b2d; }
    </style>
</head>
<body>
    <div class="container">
        <h2>D-STORE Data Flow Test</h2>
        
        <form id="insertForm">
            <div class="form-group">
                <label>Record Name:</label>
                <input type="text" id="recName" value="test-flow" required>
            </div>
            <div class="form-group">
                <label>Record Value:</label>
                <input type="number" id="recValue" value="42" required>
            </div>
            <div class="form-group">
                <label>Collection:</label>
                <select id="recCollection">
                    <option value="users">users</option>
                    <option value="products">products</option>
                    <option value="documents">documents</option>
                </select>
            </div>
            <button type="submit">Insert Record & Test Replication</button>
        </form>
        
        <div id="results"></div>
    </div>

    <script>
        document.getElementById('insertForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const name = document.getElementById('recName').value;
            const value = document.getElementById('recValue').value;
            const collection = document.getElementById('recCollection').value;
            
            const resultsDiv = document.getElementById('results');
            resultsDiv.innerHTML = '<div class="result info">Inserting record and testing replication...</div>';
            resultsDiv.style.display = 'block';
            
            // Step 1: Insert record via Pakistan node API
            try {
                const insertResp = await fetch('http://127.0.0.1:8001/api/dstore/v1/events/push', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        eventId: 'flow-test-' + Date.now(),
                        origin: 'pakistan',
                        collection: collection,
                        recordId: 'flow-test-' + Date.now(),
                        data: { name, value },
                        version: [],
                        timestamp: new Date().toISOString()
                    })
                });
                
                const insertData = await insertResp.json();
                if (!insertData.ok) {
                    throw new Error(insertData.error || 'Insert failed');
                }
            } catch (e) {
                document.getElementById('results').innerHTML = '<div class="result bad">❌ Insert failed: ' + e.message + '</div>';
                return;
            }
            
            // Step 2: Wait for sync
            await new Promise(r => setTimeout(r, 8000));
            
            // Step 3: Check all 4 nodes
            resultsDiv.innerHTML = '<div class="result info">Checking replication on all 4 nodes...</div>';
            
            const nodes = [
                { name: 'Pakistan', port: 8001 },
                { name: 'UAE', port: 8002 },
                { name: 'USA', port: 8003 },
                { name: 'UK', port: 8004 }
            ];
            
            let passed = 0;
            let total = nodes.length;
            
            for (const node of nodes) {
                try {
                    const checkResp = await fetch(`http://127.0.0.1:${node.port}/api/dstore/v1/record/${collection}/flow-test-${Date.now()}`, {
                        method: 'GET',
                        headers: { 'Accept': 'application/json' }
                    });
                    const checkData = await checkResp.json();
                    
                    const nodeHtml = `
                        <div class="result ${checkData.ok ? 'good' : 'bad'}">
                            <strong>${node.name}:</strong> ${checkData.ok ? '✅ Found' : '❌ Not found'}<br>
                            Name: ${checkData.name || 'N/A'}, Value: ${checkData.value || 'N/A'}
                        </div>
                    `;
                    resultsDiv.innerHTML += nodeHtml;
                    
                    if (checkData.ok) passed++;
                } catch (e) {
                    resultsDiv.innerHTML += `<div class="result bad"><strong>${node.name}:</strong> ❌ API error: ${e.message}</div>`;
                }
            }
            
            // Step 4: Show final result
            setTimeout(() => {
                resultsDiv.innerHTML = '';
                if (passed === total) {
                    resultsDiv.innerHTML = '<div class="result good">✅ All ' + total + ' nodes have the record! Data flow is working correctly.</div>';
                } else {
                    resultsDiv.innerHTML = '<div class="result bad">❌ Only ' + passed + ' of ' + total + ' nodes have the record. Data flow issue.</div>';
                }
                resultsDiv.style.display = 'block';
            }, 500);
        });
    </script>
</body>
</html>