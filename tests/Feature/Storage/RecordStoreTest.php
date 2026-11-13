<?php

namespace Tests\Feature\Storage;

use App\DStore\Storage\EventLog;
use App\DStore\Storage\Exceptions\CorruptRecordException;
use App\DStore\Storage\HealthState;
use App\DStore\Storage\IntegrityGuard;
use App\DStore\Storage\RecordDocument;
use App\DStore\Storage\RecordStore;
use Tests\TestCase;

class RecordStoreTest extends TestCase
{
    private function store(): RecordStore
    {
        return $this->app->make(RecordStore::class);
    }

    public function test_written_documents_round_trip_through_disk(): void
    {
        $store = $this->store();
        $document = RecordDocument::make('users', 'usr_test1', ['name' => 'Ada'], ['test-node' => 1], '2026-11-12T10:00:00Z');

        $stored = $store->write($document);
        $loaded = $store->read('users', 'usr_test1');

        $this->assertNotEmpty($stored->meta['hash']);
        $this->assertNotNull($loaded);
        $this->assertSame('Ada', $loaded->data['name']);
        $this->assertSame($stored->meta['hash'], $loaded->meta['hash']);
    }

    public function test_reading_a_missing_document_returns_null(): void
    {
        $this->assertNull($this->store()->read('users', 'usr_missing'));
    }

    public function test_tampered_document_is_detected_quarantined_and_reported(): void
    {
        $store = $this->store();
        $store->write(RecordDocument::make('users', 'usr_tamper', ['name' => 'Ada'], ['test-node' => 1], '2026-11-12T10:00:00Z'));

        $path = $this->storagePath('records/users/'.substr(hash('sha256', 'usr_tamper'), 0, 2).'/usr_tamper.json');

        $decoded = json_decode((string) file_get_contents($path), true);
        $decoded['data']['name'] = 'Mallory';
        file_put_contents($path, json_encode($decoded));

        try {
            $store->read('users', 'usr_tamper');
            $this->fail('Expected CorruptRecordException was not thrown.');
        } catch (CorruptRecordException) {
            $this->assertFileDoesNotExist($path);
        }

        $this->assertTrue($this->app->make(HealthState::class)->isDegraded());
    }

    public function test_verify_tree_reports_corrupted_files(): void
    {
        $store = $this->store();
        $store->write(RecordDocument::make('users', 'usr_ok', ['name' => 'Ada'], ['test-node' => 1], '2026-11-12T10:00:00Z'));
        $store->write(RecordDocument::make('users', 'usr_bad', ['name' => 'Ada'], ['test-node' => 1], '2026-11-12T10:00:00Z'));

        $badPath = $this->storagePath('records/users/'.substr(hash('sha256', 'usr_bad'), 0, 2).'/usr_bad.json');
        file_put_contents($badPath, '{"meta":{},"data":{}');

        $report = $this->app->make(IntegrityGuard::class)->verifyTree();

        $this->assertSame(2, $report['checked']);
        $this->assertCount(1, $report['corrupted']);
        $this->assertTrue($report['degraded']);
    }

    public function test_scan_and_count_cover_every_document(): void
    {
        $store = $this->store();
        $store->write(RecordDocument::make('users', 'usr_a', ['name' => 'A'], ['test-node' => 1], '2026-11-12T10:00:00Z'));
        $store->write(RecordDocument::make('users', 'usr_b', ['name' => 'B'], ['test-node' => 1], '2026-11-12T10:00:00Z'));

        $this->assertCount(2, iterator_to_array($store->scan('users')));
        $this->assertSame(2, $store->count('users'));
    }

    public function test_delete_removes_the_document_from_disk(): void
    {
        $store = $this->store();
        $store->write(RecordDocument::make('users', 'usr_gone', ['name' => 'A'], ['test-node' => 1], '2026-11-12T10:00:00Z'));

        $this->assertTrue($store->delete('users', 'usr_gone'));
        $this->assertFalse($store->delete('users', 'usr_gone'));
        $this->assertNull($store->read('users', 'usr_gone'));
    }

    public function test_event_log_keeps_an_append_only_history(): void
    {
        $events = $this->app->make(EventLog::class);

        $this->assertSame(1, $events->nextSeq('test-node'));
        $this->assertSame(2, $events->nextSeq('test-node'));
        $this->assertSame(0, $events->lastSeq('test-node'));
    }
}
