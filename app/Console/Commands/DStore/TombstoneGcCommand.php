<?php

namespace App\Console\Commands\DStore;

use App\DStore\Recovery\TombstoneGarbageCollector;
use Illuminate\Console\Command;

class TombstoneGcCommand extends Command
{
    protected $signature = 'dstore:gc:tombstones
        {--days= : Retention in days (default from config)}
        {--dry-run : Report what would be removed}
        {--json : Print the result as JSON}';

    protected $description = 'Remove tombstones and deleted record files past their retention';

    public function handle(TombstoneGarbageCollector $collector): int
    {
        $days = $this->option('days');
        $retention = $days !== null ? (int) $days : (int) config('dstore.storage.tombstone_gc_days', 7);

        $result = $collector->collect($retention, (bool) $this->option('dry-run'));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Scanned %d tombstone(s), %d eligible',
            $result['scanned'],
            $result['eligible'],
        ));

        if ($result['dry_run']) {
            $this->warn('Dry run: nothing was removed.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Removed %d tombstone(s) and %d record file(s)',
            $result['removed'],
            $result['records_removed'],
        ));

        return self::SUCCESS;
    }
}
