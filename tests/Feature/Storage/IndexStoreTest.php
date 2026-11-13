<?php

namespace Tests\Feature\Storage;

use App\DStore\Storage\IndexStore;
use Tests\TestCase;

class IndexStoreTest extends TestCase
{
    private function index(): IndexStore
    {
        return $this->app->make(IndexStore::class);
    }

    public function test_entries_are_stored_and_retrieved(): void
    {
        $index = $this->index();

        $index->put('users', 'usr_a', [
            'path' => 'records/users/aa/usr_a.json',
            'size' => 120,
            'hash' => 'abc',
            'updated_at' => '2026-11-12T10:00:00Z',
            'version' => ['test-node' => 1],
            'deleted' => false,
        ]);

        $entry = $index->get('users', 'usr_a');

        $this->assertNotNull($entry);
        $this->assertSame('records/users/aa/usr_a.json', $entry['path']);
        $this->assertSame(1, $index->count('users'));
    }

    public function test_the_latest_entry_wins_for_the_same_record(): void
    {
        $index = $this->index();

        $index->put('users', 'usr_a', ['updated_at' => '2026-11-12T10:00:00Z', 'version' => ['test-node' => 1]]);
        $index->put('users', 'usr_a', ['updated_at' => '2026-11-12T11:00:00Z', 'version' => ['test-node' => 2]]);

        $this->assertSame(['test-node' => 2], $index->get('users', 'usr_a')['version']);
        $this->assertSame(1, $index->count('users'));
    }

    public function test_forget_removes_a_record_from_the_index(): void
    {
        $index = $this->index();

        $index->put('users', 'usr_a', ['updated_at' => '2026-11-12T10:00:00Z']);
        $index->forget('users', 'usr_a');

        $this->assertNull($index->get('users', 'usr_a'));
        $this->assertSame(0, $index->count('users'));
    }

    public function test_by_updated_orders_records_newest_first(): void
    {
        $index = $this->index();

        $index->put('users', 'usr_old', ['updated_at' => '2026-11-12T10:00:00Z']);
        $index->put('users', 'usr_new', ['updated_at' => '2026-11-12T12:00:00Z']);

        $ordered = array_keys($index->byUpdated('users'));

        $this->assertSame(['usr_new', 'usr_old'], $ordered);
    }

    public function test_rebuild_replaces_the_index_with_the_given_entries(): void
    {
        $index = $this->index();

        $index->put('users', 'usr_stale', ['updated_at' => '2026-11-12T10:00:00Z']);

        $count = $index->rebuild('users', [
            ['record_id' => 'usr_a', 'updated_at' => '2026-11-12T10:00:00Z', 'version' => ['test-node' => 1]],
            ['record_id' => 'usr_b', 'updated_at' => '2026-11-12T11:00:00Z', 'version' => ['test-node' => 1]],
        ]);

        $this->assertSame(2, $count);
        $this->assertNull($index->get('users', 'usr_stale'));
        $this->assertNotNull($index->get('users', 'usr_a'));
        $this->assertSame(['users'], $index->collections());
    }
}
