<?php

namespace Tests\Feature\Storage;

use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\SnapshotStore;
use Tests\TestCase;

class SnapshotStoreTest extends TestCase
{
    public function test_snapshot_captures_live_records_and_verifies_its_hash(): void
    {
        $engine = $this->app->make(StorageEngineInterface::class);
        $engine->create('users', ['name' => 'Ada']);
        $engine->create('users', ['name' => 'Grace']);

        $snapshots = $this->app->make(SnapshotStore::class);
        $name = $snapshots->create('users', 'test-node');

        $this->assertFileExists($this->storagePath('snapshots/'.$name));
        $this->assertTrue($snapshots->verify($name));

        $payload = $snapshots->read($name);

        $this->assertSame('users', $payload['collection']);
        $this->assertSame(2, $payload['count']);
        $this->assertCount(2, $payload['records']);
        $this->assertSame(['test-node' => 1], $payload['vector']);
    }

    public function test_deleted_records_are_excluded_from_snapshots(): void
    {
        $engine = $this->app->make(StorageEngineInterface::class);
        $record = $engine->create('users', ['name' => 'Ada']);
        $engine->delete('users', $record->recordId);

        $snapshots = $this->app->make(SnapshotStore::class);
        $name = $snapshots->create('users', 'test-node');

        $this->assertSame(0, $snapshots->read($name)['count']);
    }

    public function test_snapshots_are_listed_and_removable(): void
    {
        $snapshots = $this->app->make(SnapshotStore::class);
        $name = $snapshots->create('users', 'test-node');

        $this->assertCount(1, $snapshots->list());
        $this->assertSame('users', $snapshots->list()[0]['collection']);

        $snapshots->delete($name);

        $this->assertCount(0, $snapshots->list());
        $this->assertFileDoesNotExist($this->storagePath('snapshots/'.$name));
    }

    public function test_a_corrupted_snapshot_fails_verification(): void
    {
        $snapshots = $this->app->make(SnapshotStore::class);
        $name = $snapshots->create('users', 'test-node');

        file_put_contents($this->storagePath('snapshots/'.$name), 'tampered');

        $this->assertFalse($snapshots->verify($name));
    }
}
