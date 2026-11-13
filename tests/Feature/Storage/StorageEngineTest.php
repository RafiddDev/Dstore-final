<?php

namespace Tests\Feature\Storage;

use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\EventLog;
use App\DStore\Storage\Exceptions\RecordAlreadyExistsException;
use App\DStore\Storage\Exceptions\RecordNotFoundException;
use App\DStore\Storage\IntegrityGuard;
use Tests\TestCase;

class StorageEngineTest extends TestCase
{
    private function engine(): StorageEngineInterface
    {
        return $this->app->make(StorageEngineInterface::class);
    }

    public function test_create_persists_the_record_and_appends_an_event(): void
    {
        $engine = $this->engine();
        $record = $engine->create('users', ['name' => 'Ada', 'email' => 'ada@example.com']);

        $this->assertStringStartsWith('usr_', $record->recordId);
        $this->assertSame('Ada', $record->data['name']);
        $this->assertSame(['test-node' => 1], $record->version());
        $this->assertNotEmpty($record->meta['hash']);

        $shard = substr(hash('sha256', $record->recordId), 0, 2);
        $this->assertFileExists($this->storagePath("records/users/{$shard}/{$record->recordId}.json"));

        $events = iterator_to_array($this->app->make(EventLog::class)->all());

        $this->assertCount(1, $events);
        $this->assertSame('record.created', $events[0]->type);
        $this->assertSame($record->recordId, $events[0]->recordId);
        $this->assertSame('test-node', $events[0]->origin);
        $this->assertStringStartsWith('test-node-00000001-', $events[0]->eventId);
    }

    public function test_create_rejects_a_duplicate_record_id(): void
    {
        $engine = $this->engine();
        $record = $engine->create('users', ['name' => 'Ada']);

        $this->expectException(RecordAlreadyExistsException::class);

        $engine->create('users', ['name' => 'Grace'], $record->recordId);
    }

    public function test_update_merges_data_bumps_version_and_keeps_history(): void
    {
        $engine = $this->engine();
        $record = $engine->create('users', ['name' => 'Ada', 'role' => 'admin']);

        $updated = $engine->update('users', $record->recordId, ['role' => 'owner']);

        $this->assertSame('Ada', $updated->data['name']);
        $this->assertSame('owner', $updated->data['role']);
        $this->assertSame(['test-node' => 2], $updated->version());

        $history = $engine->history('users', $record->recordId);

        $this->assertCount(1, $history);
        $this->assertSame(['test-node' => 1], $history[0]['version']);
    }

    public function test_update_of_a_missing_record_fails(): void
    {
        $this->expectException(RecordNotFoundException::class);

        $this->engine()->update('users', 'usr_missing', ['name' => 'Nobody']);
    }

    public function test_delete_marks_the_record_and_writes_a_tombstone(): void
    {
        $engine = $this->engine();
        $record = $engine->create('users', ['name' => 'Ada']);

        $this->assertTrue($engine->delete('users', $record->recordId));
        $this->assertFalse($engine->delete('users', $record->recordId));

        $deleted = $engine->read('users', $record->recordId);

        $this->assertTrue($deleted->isDeleted());
        $this->assertFileExists($this->storagePath('metadata/tombstones/'.$record->recordId.'.json'));
        $this->assertSame([], $engine->all('users'));
        $this->assertCount(1, $engine->all('users', true));
    }

    public function test_reading_an_unknown_record_returns_null(): void
    {
        $this->assertNull($this->engine()->read('users', 'usr_missing'));
    }

    public function test_records_are_listed_with_live_documents_only(): void
    {
        $engine = $this->engine();
        $engine->create('users', ['name' => 'Ada']);
        $engine->create('users', ['name' => 'Grace']);

        $this->assertCount(2, $engine->all('users'));
        $this->assertSame(2, $engine->count('users'));
    }

    public function test_stats_report_records_events_and_pending_deliveries(): void
    {
        $engine = $this->engine();
        $engine->create('users', ['name' => 'Ada']);

        $stats = $engine->stats();

        $this->assertSame('test-node', $stats['node_id']);
        $this->assertSame(1, $stats['total_records']);
        $this->assertSame(1, $stats['events_applied']);
        $this->assertSame(1, $stats['events_committed']);
        $this->assertFalse($stats['degraded']);
        $this->assertGreaterThan(0, $stats['storage_bytes']);
    }

    public function test_committed_events_are_queued_for_every_configured_peer(): void
    {
        config(['dstore.peers' => [
            'uae' => ['id' => 'uae', 'url' => 'http://127.0.0.1:8002'],
            'uk' => ['id' => 'uk', 'url' => 'http://127.0.0.1:8004'],
        ]]);
        $this->refreshDStoreBindings();

        $this->engine()->create('users', ['name' => 'Ada']);

        $events = $this->app->make(EventLog::class);

        $this->assertSame(1, $events->pendingCount('uae'));
        $this->assertSame(1, $events->pendingCount('uk'));
        $this->assertSame(2, $this->engine()->stats()['pending_total']);
    }

    public function test_stats_exclude_records_that_were_quarantined(): void
    {
        $engine = $this->engine();
        $record = $engine->create('users', ['name' => 'Ada']);

        $path = $this->storagePath('records/users/'.substr(hash('sha256', $record->recordId), 0, 2).'/'.$record->recordId.'.json');
        $decoded = json_decode((string) file_get_contents($path), true);
        $decoded['data']['name'] = 'Mallory';
        file_put_contents($path, json_encode($decoded));

        $this->app->make(IntegrityGuard::class)->verifyTree();

        $stats = $this->engine()->stats();

        $this->assertSame(0, $stats['total_records']);
        $this->assertSame(0, $stats['collections']['users']);
        $this->assertTrue($stats['degraded']);
    }
}
