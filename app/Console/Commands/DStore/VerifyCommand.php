<?php

namespace App\Console\Commands\DStore;

use App\DStore\Storage\Contracts\StorageEngineInterface;
use Illuminate\Console\Command;

class VerifyCommand extends Command
{
    protected $signature = 'dstore:verify';

    protected $description = 'Verify stored file integrity (sha-256) and quarantine corrupted files';

    public function handle(StorageEngineInterface $engine): int
    {
        $report = $engine->verify();

        $this->info("Checked {$report['checked']} record files, {$report['ok']} healthy.");

        foreach ($report['corrupted'] as $corrupted) {
            $this->error("Corrupted: {$corrupted['path']} ({$corrupted['reason']})");
        }

        return $report['corrupted'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
