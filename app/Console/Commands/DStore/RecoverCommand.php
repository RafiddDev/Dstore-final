<?php

namespace App\Console\Commands\DStore;

use App\DStore\Recovery\RecoveryManager;
use Illuminate\Console\Command;

class RecoverCommand extends Command
{
    protected $signature = 'dstore:recover
        {--reason=manual : Why recovery is being run}
        {--snapshot= : Restore this snapshot before replaying the log}
        {--restore-snapshot : Restore the newest snapshot}
        {--no-snapshot : Never restore a snapshot in this run}
        {--no-sync : Do not run the catch-up sync with peers}
        {--no-verify : Skip the integrity verification step}
        {--json : Print the full report as JSON}';

    protected $description = 'Rejoin a node: verify, restore, replay, rebuild indexes and resync';

    public function handle(RecoveryManager $recovery): int
    {
        $snapshot = $this->option('snapshot');
        $restoreSnapshot = (bool) $this->option('restore-snapshot') && ! (bool) $this->option('no-snapshot');

        $report = $recovery->recover([
            'reason' => (string) $this->option('reason'),
            'snapshot' => $snapshot !== null && $snapshot !== '' ? (string) $snapshot : null,
            'restore_snapshot' => $restoreSnapshot,
            'sync' => ! (bool) $this->option('no-sync'),
            'verify' => ! (bool) $this->option('no-verify'),
            'auto_snapshot_restore' => ! (bool) $this->option('no-snapshot'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($report['steps'] as $step) {
            $line = sprintf(
                '%-10s %-8s %sms',
                $step['step'] ?? '?',
                ! empty($step['ok']) ? 'ok' : 'failed',
                $step['duration_ms'] ?? '?',
            );

            if (! empty($step['skipped'])) {
                $line .= ' skipped: '.$step['skipped'];
            }

            if (! empty($step['error'])) {
                $line .= ' error: '.$step['error'];
            }

            $this->line($line);
        }

        $this->info('Recovery '.$report['status'].'.');

        return $report['status'] === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
