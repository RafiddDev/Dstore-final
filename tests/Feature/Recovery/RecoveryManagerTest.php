<?php

namespace Tests\Feature\Recovery;

use App\DStore\Recovery\RecoveryCheckpoint;
use App\DStore\Recovery\RecoveryManager;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\StoragePaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RecoveryManagerTest extends TestCase
{
    private function engine(): StorageEngineInterface
    {
        return $this->app->make(StorageEngineInterface::class);
    }

    public function test_a_node_rebuilds_records_and_indexes_and_writes_a_checkpoint(): void
    {
        $engine = $this->engine();
        $engine->create('users', ['name' => 'A'], 'usr_a');
        $engine->create('users', ['name' => 'B'], 'usr_b');

        $paths = $this->app->make(StoragePaths::class);
        File::deleteDirectory($paths->records('users'));
        File::deleteDirectory($paths->index('users'));
        @unlink($paths->appliedIndex());

        $this->refreshDStoreBindings();

        $report = $this->app->make(RecoveryManager::class)->recover([
            'reason' => 'test',
            'sync' => false,
        ]);

        $this->assertSame('completed', $report['status']);
        $this->assertSame('A', $this->engine()->read('users', 'usr_a')?->data['name']);
        $this->assertSame('B', $this->engine()->read('users', 'usr_b')?->data['name']);

        $steps = array_column($report['steps'], 'step');
        $this->assertContains('identity', $steps);
        $this->assertContains('verify', $steps);
        $this->assertContains('replay', $steps);
        $this->assertContains('indexes', $steps);

        $checkpoint = $this->app->make(RecoveryCheckpoint::class)->current();
        $this->assertSame('completed', $checkpoint['status']);
        $this->assertSame('test', $checkpoint['reason']);
        $this->assertCount(1, $this->app->make(RecoveryCheckpoint::class)->history());
    }

    public function test_recovery_can_restore_a_snapshot_first(): void
    {
        $engine = $this->engine();
        $engine->create('users', ['name' => 'A'], 'usr_a');
        $snapshot = $engine->snapshot('users');

        $paths = $this->app->make(StoragePaths::class);
        @unlink($paths->record('users', 'usr_a'));

        $report = $this->app->make(RecoveryManager::class)->recover([
            'snapshot' => $snapshot,
            'sync' => false,
        ]);

        $this->assertSame('completed', $report['status']);
        $this->assertSame('A', $this->engine()->read('users', 'usr_a')?->data['name']);

        $snapshotStep = collect($report['steps'])->firstWhere('step', 'snapshot');
        $this->assertSame(1, $snapshotStep['restored']);
    }

    public function test_a_failed_snapshot_is_reported_as_a_failed_recovery(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->app->make(RecoveryManager::class)->recover([
            'snapshot' => 'does-not-exist.snap',
            'sync' => false,
        ]);
    }

    public function test_recovery_without_a_peer_still_completes(): void
    {
        $report = $this->app->make(RecoveryManager::class)->recover([
            'sync' => true,
            'verify' => false,
        ]);

        $this->assertSame('completed', $report['status']);
        $sync = collect($report['steps'])->firstWhere('step', 'sync');
        $this->assertSame(0, $sync['peers']);
    }
}
