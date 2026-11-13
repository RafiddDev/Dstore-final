<?php

namespace App\Console\Commands\DStore;

use App\DStore\Recovery\IndexRebuilder;
use Illuminate\Console\Command;

class IndexRebuildCommand extends Command
{
    protected $signature = 'dstore:index:rebuild {collection? : Rebuild only this collection} {--json : Print the result as JSON}';

    protected $description = 'Rebuild the record indexes from the record files';

    public function handle(IndexRebuilder $indexes): int
    {
        $collection = $this->argument('collection');

        $result = $collection !== null
            ? ['collections' => [(string) $collection => $indexes->rebuild((string) $collection)]]
            : $indexes->rebuildAll();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($result['collections'] === []) {
            $this->warn('No record collections found.');

            return self::SUCCESS;
        }

        foreach ($result['collections'] as $name => $count) {
            $this->line(sprintf('%-20s %d record(s)', $name, $count));
        }

        return self::SUCCESS;
    }
}
