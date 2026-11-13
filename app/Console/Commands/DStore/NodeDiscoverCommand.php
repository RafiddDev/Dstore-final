<?php

namespace App\Console\Commands\DStore;

use App\DStore\Monitoring\PeerStatusStore;
use App\DStore\Network\PeerDiscoveryService;
use Illuminate\Console\Command;

class NodeDiscoverCommand extends Command
{
    protected $signature = 'dstore:node:discover {peer? : Probe only this peer}';

    protected $description = 'Probe the configured peers and report which ones answer';

    public function handle(PeerDiscoveryService $discovery, PeerStatusStore $statuses): int
    {
        $peerId = $this->argument('peer');

        $results = $discovery->probe($peerId !== null ? (string) $peerId : null);

        if ($results === []) {
            $this->warn('No peers are configured for this node.');

            return self::SUCCESS;
        }

        foreach ($results as $result) {
            if ($result['reachable']) {
                $this->info(sprintf(
                    '%-10s %-12s %sms',
                    $result['peer'],
                    'reachable',
                    $result['response_ms'] ?? '?',
                ));
            } else {
                $this->warn(sprintf(
                    '%-10s %-12s %s',
                    $result['peer'],
                    'unreachable',
                    $result['error'] ?? 'unknown error',
                ));
            }
        }

        $reachable = count(array_filter($results, fn (array $r): bool => $r['reachable']));

        $this->newLine();
        $this->line("Reachable: {$reachable}/".count($results));

        return $reachable === count($results) ? self::SUCCESS : self::FAILURE;
    }
}
