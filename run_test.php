<?php
// D-STORE Data Flow Test Script
// Run: php run_test.php

echo "D-STORE Data Flow Test\n";
echo "====================\n\n";

// Step 1: Create a record from Pakistan
echo "Step 1: Creating record from Pakistan...\n";
$cmd = 'C:\laragon\bin\php\php-8.3\php.exe artisan --env=pakistan dstore:record create users --payload=\'{"name":"manual-test-flow","value":99}\'';
$output = shell_exec($cmd);
echo "Output: " . trim($output) . "\n\n";

// Wait for sync
echo "Step 2: Waiting 10 seconds for sync...\n";
sleep(10);

// Step 3: Check all 4 nodes
echo "Step 3: Checking all 4 nodes...\n";
$nodes = [
    ['name' => 'Pakistan', 'port' => 8001],
    ['name' => 'UAE', 'port' => 8002],
    ['name' => 'USA', 'port' => 8003],
    ['name' => 'UK', 'port' => 8004],
];

foreach ($nodes as $node) {
    $url = "http://127.0.0.1:" . $node['port'] . "/api/dstore/v1/record/users/manual-test-flow";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $decoded = json_decode($data, true);
    $status = $decoded['ok'] ?? ($httpCode === 200 ? 'found' : 'not found');
    echo $node['name'] . ": " . $decoded['name'] . " - " . $decoded['value'] . " - " . $status . "\n";
}

echo "\nStep 4: Checking via PHP artisan commands...\n";
echo "Run these commands:\n";
echo "1. php artisan --env=pakistan dstore:record show users manual-test-flow\n";
echo "2. for node in pakistan uae usa uk; do echo \"\$node:\"; php artisan --env=\$node dstore:record show users manual-test-flow; done\n";

echo "\n✅ Test complete!\n";