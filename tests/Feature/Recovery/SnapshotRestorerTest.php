<?php

namespace Tests\Feature\Recovery;

use App\DStore\Recovery\SnapshotRestorer;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\StoragePaths;
use Tests\TestCase;

class SnapshotRestorerTest extends TestCase
{
    private function engine(): StorageEngineInterface
    {
        return $this->app->make(StorageEngineInterface::class);
    }

    public function test_a_missing_record_is_restored_from_the_snapshot(): void
    {
        $engine = $this->engine();
        $engine->create('users', ['name' => 'A'], 'usr_a');

        $snapshot = $engine->snapshot('users');

        $paths = $this->app->make(StoragePaths::class);
        @unlink($paths->record('users', 'usr_a'));

        $result = $this->app->make(SnapshotRestorer::class)->restore($snapshot);

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['restored']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame('A', $this->engine()->read('users', 'usr_a')?->data['name']);
    }

    public function test_a_newer_local_record_is_kept_over_the_snapshot(): void
    {
        $engine = $this->engine();
        $engine->create('users', ['name' => 'old'], 'usr_a');

        $snapshot = $engine->snapshot('users');

        $engine->update('users', 'usr_a', ['name' => 'newer']);

        $result = $this->app->make(SnapshotRestorer::class)->restore($snapshot);

        $this->assertSame(0, $result['restored']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('newer', $this->engine()->read('users', 'usr_a')?->data['name']);
    }

    public function test_a_tampered_snapshot_is_refused(): void
    {
        $engine = $this->engine();
        $engine->create('users', ['name' => 'A'], 'usr_a');
        $snapshot = $engine->snapshot('users');

        $paths = $this->app->make(StoragePaths::class);
        file_put_contents($paths->snapshots().'/'.$snapshot, 'tampered', FILE_APPEND);

        $this->expectException(\RuntimeException::class);

        $this->app->make(SnapshotRestorer::class)->restore($snapshot);
    }
}
