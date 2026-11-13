<?php

namespace App\Console\Commands\DStore;

use App\DStore\Recovery\BackupManager;
use Illuminate\Console\Command;

class BackupVerifyCommand extends Command
{
    protected $signature = 'dstore:backup:verify {name : Backup name} {--json : Print the result as JSON}';

    protected $description = 'Re-hash a backup and report any mismatch';

    public function handle(BackupManager $backups): int
    {
        $name = (string) $this->argument('name');
        $result = $backups->verify($name);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->line(sprintf('Checked %d file(s), %d bytes', $result['checked'], $result['bytes']));

        if ($result['ok']) {
            $this->info('Backup is intact.');

            return self::SUCCESS;
        }

        foreach (['mismatches' => 'hash mismatch', 'missing' => 'missing', 'unexpected' => 'unexpected'] as $key => $label) {
            foreach ($result[$key] as $path) {
                $this->error(sprintf('%s: %s', $label, $path));
            }
        }

        return self::FAILURE;
    }
}
