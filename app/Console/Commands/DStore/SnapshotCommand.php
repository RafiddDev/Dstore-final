<?php

namespace App\Console\Commands\DStore;

use App\DStore\Storage\Contracts\StorageEngineInterface;
use Illuminate\Console\Command;

class SnapshotCommand extends Command
{
    protected $signature = 'dstore:snapshot {collection : Collection to snapshot}';

    protected $description = 'Create a verified snapshot of a collection';

    public function handle(StorageEngineInterface $engine): int
    {
        $name = $engine->snapshot((string) $this->argument('collection'));

        $this->info("Snapshot created: {$name}");

        return self::SUCCESS;
    }
}
