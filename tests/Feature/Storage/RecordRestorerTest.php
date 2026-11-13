<?php

namespace Tests\Feature\Storage;

use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\Event;
use App\DStore\Storage\EventLog;
use App\DStore\Storage\IndexStore;
use App\DStore\Storage\RecordDocument;
use App\DStore\Storage\RecordRestorer;
use App\DStore\Storage\RecordStore;
use Tests\TestCase;

class RecordRestorerTest extends TestCase
{
    private function engine(): StorageEngineInterface
    {
        return $this->app->make(StorageEngineInterface::class);
    }

    private function restorer(): RecordRestorer
    {
        return $this->app->make(RecordRestorer::class);
    }

    private function store(): RecordStore
    {
        return $this->app->make(RecordStore::class);
    }

    public function test_a_record_the_log_knows_but_the_store_lost_is_rebuilt(): void
    {
        $this->engine()->create('users', ['name' => 'Survivor'], 'usr_x');

        $path = $this->storagePath('records/users/'.substr(hash('sha256', 'usr_x'), 0, 2).'/usr_x.json');

        $this->assertFileExists($path);

        unlink($path);

        $this->assertNull($this->engine()->read('users', 'usr_x'));

        $result = $this->restorer()->restore();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['restored']);
        $this->assertSame(['usr_x'], $result['missing']);

        $record = $this->engine()->read('users', 'usr_x');

        $this->assertNotNull($record);
        $this->assertSame(['name' => 'Survivor'], $record->data);
        $this->assertSame(['test-node' => 1], $record->version());
    }

    public function test_a_restored_record_is_indexed_again(): void
    {
        $this->engine()->create('users', ['name' => 'Survivor'], 'usr_x');

        unlink($this->storagePath('records/users/'.substr(hash('sha256', 'usr_x'), 0, 2).'/usr_x.json'));

        $this->restorer()->restore();

        $index = $this->app->make(IndexStore::class);

        $this->assertArrayHasKey('usr_x', $index->all('users'));
        $this->assertCount(1, $this->engine()->all('users'));
    }

    public function test_a_record_that_is_present_is_never_overwritten_by_the_log(): void
    {
        $this->engine()->create('users', ['name' => 'Original'], 'usr_x');

        // The store moved ahead of the log: a newer, valid local state.
        $current = $this->engine()->read('users', 'usr_x');
        $this->assertNotNull($current);

        $this->store()->write(
            $current->withData(['name' => 'Locally changed'], ['test-node' => 2], '2026-11-12T12:00:00Z'),
        );

        $result = $this->restorer()->restore();

        $this->assertSame(0, $result['restored']);

        $record = $this->engine()->read('users', 'usr_x');

        $this->assertNotNull($record);
        $this->assertSame(['name' => 'Locally changed'], $record->data);
    }

    public function test_the_missing_records_are_listed_without_restoring_them(): void
    {
        $this->engine()->create('users', ['name' => 'Survivor'], 'usr_x');

        unlink($this->storagePath('records/users/'.substr(hash('sha256', 'usr_x'), 0, 2).'/usr_x.json'));

        $this->assertSame(['usr_x'], $this->restorer()->missing());
        $this->assertNull($this->engine()->read('users', 'usr_x'));
    }

    public function test_reconcile_fills_in_the_writer_the_log_already_proves(): void
    {
        $this->engine()->create('users', ['name' => 'Legacy'], 'usr_x');

        $current = $this->engine()->read('users', 'usr_x');
        $this->assertNotNull($current);

        // A record written before the writer was tracked.
        $this->store()->write($current->withWriter(''));

        $result = $this->restorer()->reconcile();

        $this->assertSame(1, $result['updated']);

        $record = $this->engine()->read('users', 'usr_x');

        $this->assertNotNull($record);
        $this->assertSame('test-node', $record->meta['writer']);
        $this->assertSame(['name' => 'Legacy'], $record->data);
        $this->assertSame(['test-node' => 1], $record->version());
    }

    public function test_reconcile_drops_a_history_entry_the_event_already_carries(): void
    {
        $event = $this->eventWithHistory();

        $this->app->make(EventLog::class)->append($event);

        $document = RecordDocument::fromEvent($event);

        // The state that was replaced, recorded a second time.
        $duplicated = array_merge($document->history(), [[
            'version' => $document->history()[0]['version'],
            'updated_at' => $document->history()[0]['updated_at'],
            'deleted' => $document->history()[0]['deleted'],
            'writer' => 'uae',
        ]]);

        $this->store()->write($document->withHistory($duplicated));

        $result = $this->restorer()->reconcile();

        $this->assertSame(1, $result['updated']);

        $record = $this->engine()->read('users', 'usr_remote_1');

        $this->assertNotNull($record);
        $this->assertCount(1, $record->history());
        $this->assertSame($document->history()[0]['version'], $record->history()[0]['version']);
    }

    public function test_reconcile_keeps_a_history_entry_the_event_does_not_know_about(): void
    {
        $event = $this->eventWithHistory();

        $this->app->make(EventLog::class)->append($event);

        $document = RecordDocument::fromEvent($event);

        $local = array_merge($document->history(), [[
            'version' => ['pakistan' => 1],
            'updated_at' => '2026-11-12T09:00:00Z',
            'deleted' => false,
            'writer' => 'pakistan',
        ]]);

        $this->store()->write($document->withHistory($local));

        $result = $this->restorer()->reconcile();

        $this->assertSame(0, $result['updated']);

        $record = $this->engine()->read('users', 'usr_remote_1');

        $this->assertNotNull($record);
        $this->assertCount(2, $record->history());
    }

    public function test_reconcile_leaves_a_record_the_log_does_not_describe_alone(): void
    {
        $this->engine()->create('users', ['name' => 'Local'], 'usr_x');

        $current = $this->engine()->read('users', 'usr_x');
        $this->assertNotNull($current);

        $this->store()->write(
            $current->withData(['name' => 'Moved on'], ['test-node' => 2], '2026-11-12T12:00:00Z'),
        );

        $result = $this->restorer()->reconcile();

        $this->assertSame(0, $result['updated']);

        $record = $this->engine()->read('users', 'usr_x');

        $this->assertNotNull($record);
        $this->assertSame(['name' => 'Moved on'], $record->data);
    }

    public function test_a_dry_run_reports_without_changing_anything(): void
    {
        $this->engine()->create('users', ['name' => 'Legacy'], 'usr_x');

        $current = $this->engine()->read('users', 'usr_x');
        $this->assertNotNull($current);

        $this->store()->write($current->withWriter(''));

        $result = $this->restorer()->reconcile(true);

        $this->assertSame(1, $result['updated']);

        $record = $this->engine()->read('users', 'usr_x');

        $this->assertNotNull($record);
        $this->assertSame('', (string) $record->meta['writer']);
    }

    /**
     * An update event whose payload already carries the state it replaced.
     */
    private function eventWithHistory(): Event
    {
        $document = RecordDocument::make(
            'users',
            'usr_remote_1',
            ['name' => 'First'],
            ['uae' => 1],
            '2026-11-12T10:00:00Z',
        );

        $document = $document->withData(
            ['name' => 'Second'],
            ['uae' => 2],
            '2026-11-12T11:00:00Z',
        );

        return Event::compose(
            'uae',
            2,
            Event::TYPE_UPDATED,
            $document,
            ['uae' => 2],
            '2026-11-12T11:00:00Z',
            null,
        );
    }
}
