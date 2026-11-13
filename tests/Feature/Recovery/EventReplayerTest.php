<?php

namespace Tests\Feature\Recovery;

use App\DStore\Recovery\EventReplayer;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\IndexStore;
use App\DStore\Storage\StoragePaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EventReplayerTest extends TestCase
{
    private function engine(): StorageEngineInterface
    {
        return $this->app->make(StorageEngineInterface::class);
    }

    public function test_replaying_the_log_recreates_lost_records(): void
    {
        $engine = $this->engine();
        $engine->create('users', ['name' => 'A'], 'usr_a');
        $engine->create('users', ['name' => 'B'], 'usr_b');
        $engine->update('users', 'usr_a', ['name' => 'A2']);

        $paths = $this->app->make(StoragePaths::class);

        File::deleteDirectory($paths->records('users'));
        File::deleteDirectory($paths->index('users'));
        @unlink($paths->appliedIndex());

        $this->refreshDStoreBindings();

        $report = $this->app->make(EventReplayer::class)->replay();

        $this->assertSame(3, $report['checked']);
        $this->assertGreaterThanOrEqual(2, $report['applied']);
        $this->assertSame(2, $report['records']);
        $this->assertSame('A2', $this->engine()->read('users', 'usr_a')?->data['name']);
        $this->assertSame('B', $this->engine()->read('users', 'usr_b')?->data['name']);
        $this->assertCount(2, $this->app->make(IndexStore::class)->all('users'));
    }

    public function test_replaying_twice_does_not_duplicate_or_overwrite(): void
    {
        $engine = $this->engine();
        $engine->create('users', ['name' => 'A'], 'usr_a');

        $replayer = $this->app->make(EventReplayer::class);
        $first = $replayer->replay();
        $second = $replayer->replay();

        $this->assertSame(1, $first['checked']);
        $this->assertSame(1, $second['checked']);
        $this->assertSame(1, $second['duplicate']);
        $this->assertSame('A', $this->engine()->read('users', 'usr_a')?->data['name']);
    }

    public function test_a_newer_local_record_is_never_replaced_by_an_older_event(): void
    {
        $engine = $this->engine();
        $engine->create('users', ['name' => 'A'], 'usr_a');
        $engine->update('users', 'usr_a', ['name' => 'newer']);

        $paths = $this->app->make(StoragePaths::class);
        @unlink($paths->appliedIndex());

        $this->refreshDStoreBindings();
        $this->app->make(EventReplayer::class)->replay();

        $this->assertSame('newer', $this->engine()->read('users', 'usr_a')?->data['name']);
    }
}
