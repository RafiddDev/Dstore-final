<?php

namespace Tests\Feature\Recovery;

use App\DStore\Recovery\IndexRebuilder;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\IndexStore;
use App\DStore\Storage\StoragePaths;
use Tests\TestCase;

class IndexRebuilderTest extends TestCase
{
    private function engine(): StorageEngineInterface
    {
        return $this->app->make(StorageEngineInterface::class);
    }

    public function test_indexes_are_rebuilt_from_the_record_files(): void
    {
        $engine = $this->engine();
        $engine->create('users', ['name' => 'A'], 'usr_a');
        $engine->create('users', ['name' => 'B'], 'usr_b');

        $paths = $this->app->make(StoragePaths::class);
        @unlink($paths->byIdIndex('users'));
        @unlink($paths->byUpdatedIndex('users'));

        $this->assertSame([], $this->app->make(IndexStore::class)->all('users'));

        $result = $this->app->make(IndexRebuilder::class)->rebuildAll();

        $this->assertSame(2, $result['collections']['users']);
        $this->assertSame(2, $result['records']);
        $this->assertCount(2, $this->app->make(IndexStore::class)->all('users'));
        $this->assertCount(2, $engine->all('users'));
    }

    public function test_rebuilding_one_collection_leaves_the_others_alone(): void
    {
        $engine = $this->engine();
        $engine->create('users', ['name' => 'A'], 'usr_a');
        $engine->create('documents', ['title' => 'D'], 'doc_a');

        $paths = $this->app->make(StoragePaths::class);
        @unlink($paths->byIdIndex('users'));

        $rebuilt = $this->app->make(IndexRebuilder::class)->rebuild('users');

        $this->assertSame(1, $rebuilt);
        $this->assertCount(1, $this->app->make(IndexStore::class)->all('users'));
        $this->assertCount(1, $this->app->make(IndexStore::class)->all('documents'));
    }

    public function test_rebuilding_an_empty_node_reports_no_collections(): void
    {
        $result = $this->app->make(IndexRebuilder::class)->rebuildAll();

        $this->assertSame([], $result['collections']);
        $this->assertSame(0, $result['records']);
    }
}
