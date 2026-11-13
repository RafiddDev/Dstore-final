<?php

namespace App\Console\Commands\DStore;

use App\DStore\Monitoring\MonitorService;
use App\DStore\Monitoring\NodeRuntime;
use Illuminate\Console\Command;

/**
 * The long-running monitoring service.
 *
 * Starting this command is what creates a new runtime epoch: a restart of the
 * monitor is a restart of the node from the network's point of view. HTTP
 * workers never call beginRun(), so serving a request never looks like a
 * restart.
 */
class MonitorCommand extends Command
{
    protected $signature = 'dstore:monitor
        {--interval=5 : Seconds between monitoring rounds}
        {--once : Run a single round and exit}
        {--no-gossip : Do not share observations with peers}
        {--json : Print the raw round as JSON}';

    protected $description = 'Heartbeat every peer, update failure states and gossip the result';

    public function handle(MonitorService $monitor, NodeRuntime $runtime): int
    {
        $interval = max(1, (int) $this->option('interval'));
        $once = (bool) $this->option('once');
        $gossip = ! (bool) $this->option('no-gossip') && (bool) config('dstore.monitoring.gossip', true);

        $runtime->beginRun();

        do {
            $report = $monitor->run($gossip);

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->render($report);
            }

            if ($once) {
                break;
            }

            sleep($interval);
        } while (true);

        return self::SUCCESS;
    }

    private function render(array $report): void
    {
        $rows = [];

        foreach ($report['peers'] as $peerId => $peer) {
            $detected = (array) ($peer['detected'] ?? []);

            $rows[] = [
                $peerId,
                (string) ($detected['state'] ?? $peer['state'] ?? 'unknown'),
                $peer['reachable'] ? 'yes' : 'no',
                isset($peer['response_ms']) ? $peer['response_ms'].'ms' : '-',
                $peer['epoch'] !== '' ? substr((string) $peer['epoch'], 0, 12) : '-',
                $peer['restarted'] ? 'restarted' : '',
                (string) ($peer['error'] ?? ''),
            ];
        }

        $this->line(sprintf(
            'self %s (%s) epoch %s uptime %ss records %d degraded %s',
            $report['self']['name'],
            $report['self']['node_id'],
            substr((string) $report['self']['epoch'], 0, 12),
            $report['self']['uptime_seconds'],
            $report['self']['records'],
            $report['self']['degraded'] ? 'yes' : 'no',
        ));

        if ($rows === []) {
            $this->warn('No peers are configured for this node.');

            return;
        }

        $this->table(['peer', 'state', 'reachable', 'latency', 'epoch', 'note', 'error'], $rows);
        $this->line(sprintf(
            'Online: %d/%d · gossiped to %d peer(s)',
            $report['online'],
            $report['total'],
            $report['gossiped'],
        ));
    }
}
