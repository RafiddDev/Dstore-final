<?php

namespace Tests\Feature\ConflictResolution;

use App\DStore\ConflictResolution\ConflictLog;
use App\DStore\ConflictResolution\RecordComparer;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\Event;
use App\DStore\Storage\RecordDocument;
use App\DStore\Synchronization\EventApplier;
use Tests\Support\BuildsPeerEvents;
use Tests\TestCase;

class ConflictLogTest extends TestCase
{
    use BuildsPeerEvents;

    public function test_a_conflict_is_recorded_with_both_versions(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $this->app->make(StorageEngineInterface::class)->create('users', ['name' => 'Local'], 'usr_remote_1');

        $this->app->make(EventApplier::class)->apply(
            $this->signedEvent($peer, 'uae', 1, 'users', 'usr_remote_1', ['name' => 'Remote'], ['uae' => 1])
        );

        $entries = $this->app->make(ConflictLog::class)->all();

        $this->assertCount(1, $entries);
        $this->assertSame('usr_remote_1', $entries[0]['record_id']);
        $this->assertSame('uae', $entries[0]['origin']);
        $this->assertSame(['test-node' => 1], $entries[0]['local_version']);
        $this->assertSame(['uae' => 1], $entries[0]['incoming_version']);
        $this->assertSame(1, $this->app->make(ConflictLog::class)->count());
    }

    public function test_a_write_that_is_not_a_conflict_is_not_recorded(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $this->app->make(EventApplier::class)->apply(
            $this->signedEvent($peer, 'uae', 1, 'users', 'usr_remote_1', ['name' => 'Remote'], ['uae' => 1])
        );

        $this->assertSame(0, $this->app->make(ConflictLog::class)->count());
    }

    public function test_the_comparer_reports_that_both_sides_have_the_same_version(): void
    {
        $this->app->make(StorageEngineInterface::class)->create('users', ['name' => 'Local'], 'usr_x');

        $records = $this->app->make(RecordComparer::class)->compare('users', ['usr_x'], [
            'usr_x' => ['test-node' => 1],
        ]);

        $this->assertCount(1, $records);
        $this->assertTrue($records[0]['found']);
        $this->assertSame(['test-node' => 1], $records[0]['version']);
        $this->assertSame('test-node', $records[0]['writer']);
        $this->assertSame('in-sync', $records[0]['relation']);
        $this->assertNotSame('', $records[0]['hash']);
    }

    public function test_the_comparer_reports_a_peer_that_is_ahead(): void
    {
        $this->app->make(StorageEngineInterface::class)->create('users', ['name' => 'Local'], 'usr_x');

        $records = $this->app->make(RecordComparer::class)->compare('users', ['usr_x'], [
            'usr_x' => ['test-node' => 1, 'uae' => 3],
        ]);

        $this->assertSame('peer-newer', $records[0]['relation']);
    }

    public function test_the_comparer_reports_two_concurrent_versions(): void
    {
        $this->app->make(StorageEngineInterface::class)->create('users', ['name' => 'Local'], 'usr_x');

        $records = $this->app->make(RecordComparer::class)->compare('users', ['usr_x'], [
            'usr_x' => ['uae' => 1],
        ]);

        $this->assertSame('concurrent', $records[0]['relation']);
    }

    public function test_the_comparer_reports_a_record_this_node_does_not_have(): void
    {
        $records = $this->app->make(RecordComparer::class)->compare('users', ['usr_missing'], [
            'usr_missing' => ['uae' => 1],
        ]);

        $this->assertFalse($records[0]['found']);
        $this->assertSame('missing-local', $records[0]['relation']);
    }
}
