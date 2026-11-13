<?php

namespace App\Console\Commands\DStore;

use App\DStore\Recovery\BackupManager;
use Illuminate\Console\Command;

class BackupCommand extends Command
{
    protected $signature = 'dstore:backup
        {--name= : Explicit backup name (default: timestamp)}
        {--keep= : Number of backups to retain (default from config)}
        {--json : Print the result as JSON}';

    protected $description = 'Create a verifiable full-tree backup of the storage';

    public function handle(BackupManager $backups): int
    {
        $name = $this->option('name');
        $result = $backups->create($name !== null && $name !== '' ? (string) $name : null);

        $keep = $this->option('keep');
        $retention = $keep !== null ? (int) $keep : (int) config('dstore.storage.backup_retention', 0);
        $removed = $backups->prune($retention);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result + ['pruned' => $removed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'Backup %s created: %d files, %d bytes',
                $result['name'],
                $result['files'],
                $result['bytes'],
            ));

            if ($removed !== []) {
                $this->line('Pruned: '.implode(', ', $removed));
            }
        }

        return self::SUCCESS;
    }
}
