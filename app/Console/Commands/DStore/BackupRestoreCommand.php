<?php

namespace App\Console\Commands\DStore;

use App\DStore\Recovery\BackupManager;
use Illuminate\Console\Command;

class BackupRestoreCommand extends Command
{
    protected $signature = 'dstore:backup:restore
        {name : Backup name}
        {--skip-verify : Restore without verifying the manifest first}
        {--force : Required to overwrite the current storage}';

    protected $description = 'Restore a backup after verifying every hash';

    public function handle(BackupManager $backups): int
    {
        if (! (bool) $this->option('force')) {
            $this->error('Refusing to overwrite storage without --force.');

            return self::FAILURE;
        }

        $name = (string) $this->argument('name');
        $result = $backups->restore($name, ! (bool) $this->option('skip-verify'));

        $this->info(sprintf(
            'Restored %s: %d file(s), %d skipped',
            $result['name'],
            $result['restored'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
