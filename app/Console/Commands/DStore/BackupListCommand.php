<?php

namespace App\Console\Commands\DStore;

use App\DStore\Recovery\BackupManager;
use Illuminate\Console\Command;

class BackupListCommand extends Command
{
    protected $signature = 'dstore:backup:list {--json : Print the list as JSON}';

    protected $description = 'List the available storage backups';

    public function handle(BackupManager $backups): int
    {
        $backupsList = $backups->list();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($backupsList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($backupsList === []) {
            $this->warn('No backups found.');

            return self::SUCCESS;
        }

        $this->table(
            ['name', 'created_at', 'files', 'bytes'],
            array_map(fn (array $backup): array => [
                (string) ($backup['name'] ?? ''),
                (string) ($backup['created_at'] ?? ''),
                (string) ($backup['count'] ?? 0),
                (string) ($backup['bytes'] ?? 0),
            ], $backupsList),
        );

        return self::SUCCESS;
    }
}
