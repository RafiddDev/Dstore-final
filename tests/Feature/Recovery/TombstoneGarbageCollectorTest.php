<?php

namespace Tests\Feature\Recovery;

use App\DStore\Recovery\TombstoneGarbageCollector;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\IndexStore;
use App\DStore\Storage\StoragePaths;
use App\DStore\Storage\TombstoneStore;
use Tests\TestCase;

class TombstoneGarbageCollectorTest extends TestCase
{
    private function engine(): StorageEngineInterface
    {
        return $this->app->make(StorageEngineInterface::class);
    }

    public function test_a_tombstone_past_retention_takes_its_record_with_it(): void
    {
        $engine = $this->engine();
        $engine->create('users', ['name' => 'A'], 'usr_a');
        $engine->delete('users', 'usr_a');

        $paths = $this->app->make(StoragePaths::class);
        $this->assertTrue(is_file($paths->tombstone('usr_a')));
        $this->assertTrue(is_file($paths->record('users', 'usr_a')));

        $result = $this->app->make(TombstoneGarbageCollector::class)->collect(0);

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(1, $result['eligible']);
        $this->assertSame(1, $result['removed']);
        $this->assertSame(1, $result['records_removed']);
        $this->assertFalse(is_file($paths->tombstone('usr_a')));
        $this->assertFalse(is_file($paths->record('users', 'usr_a')));
        $this->assertArrayNotHasKey('usr_a', $this->app->make(IndexStore::class)->all('users'));
    }

    public function test_a_recent_tombstone_is_kept(): void
    {
        $engine = $this->engine();
        $engine->create('users', ['name' => 'A'], 'usr_a');
        $engine->delete('users', 'usr_a');

        $result = $this->app->make(TombstoneGarbageCollector::class)->collect(7);

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(0, $result['eligible']);
        $this->assertSame(0, $result['removed']);
        $this->assertTrue($this->app->make(TombstoneStore::class)->has('usr_a'));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $engine = $this->engine();
        $engine->create('users', ['name' => 'A'], 'usr_a');
        $engine->delete('users', 'usr_a');

        $result = $this->app->make(TombstoneGarbageCollector::class)->collect(0, true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['eligible']);
        $this->assertSame(0, $result['removed']);
        $this->assertTrue($this->app->make(TombstoneStore::class)->has('usr_a'));
    }
}
