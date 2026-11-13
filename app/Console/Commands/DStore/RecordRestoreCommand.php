<?php

namespace App\Console\Commands\DStore;

use App\DStore\Storage\RecordRestorer;
use Illuminate\Console\Command;

/**
 * Rebuilds the records this node's log knows about but the record store has
 * lost.
 *
 * The log is the authority: it holds the full state of every record, so
 * nothing is lost for good. A record that is present is never overwritten.
 */
class RecordRestoreCommand extends Command
{
    protected $signature = 'dstore:records:restore
        {--check : Report what is missing without restoring anything}';

    protected $description = 'Restore the records that are missing from the record store but known to the event log';

    public function handle(RecordRestorer $restorer): int
    {
        if ($this->option('check')) {
            $missing = $restorer->missing();

            $this->line('Records missing from the store: '.count($missing));

            foreach ($missing as $recordId) {
                $this->warn('  '.$recordId);
            }

            return $missing === [] ? self::SUCCESS : self::FAILURE;
        }

        $result = $restorer->restore();

        $this->line('Records known to the log: '.$result['checked']);
        $this->line('Restored:                '.$result['restored']);

        foreach ($result['missing'] as $recordId) {
            $this->info('  restored '.$recordId);
        }

        return self::SUCCESS;
    }
}
