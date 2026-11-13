<?php

namespace App\Console\Commands\DStore;

use App\DStore\Storage\Contracts\StorageEngineInterface;
use Illuminate\Console\Command;

class StatsCommand extends Command
{
    protected $signature = 'dstore:stats';

    protected $description = 'Show D-STORE storage statistics for this node';

    public function handle(StorageEngineInterface $engine): int
    {
        $stats = $engine->stats();

        $this->info("Node: {$stats['node_id']}");
        $this->line('Records: '.$stats['total_records']);

        foreach ($stats['collections'] as $collection => $count) {
            $this->line("  - {$collection}: {$count}");
        }

        $this->line('Events committed: '.$stats['events_committed']);
        $this->line('Events applied: '.$stats['events_applied']);
        $this->line('Pending deliveries: '.$stats['pending_total']);

        foreach ($stats['pending'] as $peer => $count) {
            $this->line("  - {$peer}: {$count}");
        }

        $this->line('Storage bytes: '.$stats['storage_bytes']);
        $this->line('Conflicts resolved: '.$stats['conflicts']);
        $this->line('Degraded: '.($stats['degraded'] ? 'yes' : 'no'));

        foreach ($stats['issues'] as $issue) {
            $this->warn("  ! {$issue['path']}: {$issue['reason']}");
        }

        return self::SUCCESS;
    }
}
