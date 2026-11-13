<?php

namespace App\Console\Commands\DStore;

use App\DStore\Storage\RecordRestorer;
use Illuminate\Console\Command;

/**
 * Makes the stored records agree with what the event log already proves.
 *
 * Only metadata is reconciled; a record's data, version and history are
 * never rewritten.
 */
class RecordReconcileCommand extends Command
{
    protected $signature = 'dstore:records:reconcile
        {--check : Report what would change without changing anything}';

    protected $description = 'Reconcile the record metadata with the event log';

    public function handle(RecordRestorer $restorer): int
    {
        if ($this->option('check')) {
            $result = $restorer->reconcile(true);

            $this->line('Records known to the log: '.$result['checked']);
            $this->line('Would be reconciled:        '.$result['updated']);

            foreach ($result['records'] as $recordId) {
                $this->warn('  '.$recordId);
            }

            return $result['updated'] === 0 ? self::SUCCESS : self::FAILURE;
        }

        $result = $restorer->reconcile();

        $this->line('Records known to the log: '.$result['checked']);
        $this->line('Reconciled:               '.$result['updated']);

        foreach ($result['records'] as $recordId) {
            $this->info('  reconciled '.$recordId);
        }

        return self::SUCCESS;
    }
}
