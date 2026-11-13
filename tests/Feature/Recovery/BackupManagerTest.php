<?php

namespace Tests\Feature\Recovery;

use App\DStore\Recovery\BackupManager;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\StoragePaths;
use Tests\TestCase;

class BackupManagerTest extends TestCase
{
    private function backups(): BackupManager
    {
        return $this->app->make(BackupManager::class);
    }

    private function engine(): StorageEngineInterface
    {
        return $this->app->make(StorageEngineInterface::class);
    }

    public function test_a_backup_copies_the_tree_and_writes_a_verified_manifest(): void
    {
        $engine = $this->engine();
        $engine->create('users', ['name' => 'A'], 'usr_a');

        $result = $this->backups()->create('first');

        $this->assertSame('first', $result['name']);
        $this->assertGreaterThan(0, $result['files']);
        $this->assertGreaterThan(0, $result['bytes']);

        $manifest = $this->backups()->manifest('first');
        $this->assertSame('first', $manifest['name']);
        $this->assertSame($result['files'], $manifest['count']);

        $verify = $this->backups()->verify('first');
        $this->assertTrue($verify['ok']);
        $this->assertSame($result['files'], $verify['checked']);
    }

    public function test_the_backups_directory_does_not_contain_itself(): void
    {
        $this->engine()->create('users', ['name' => 'A'], 'usr_a');

        $first = $this->backups()->create('first');
        $second = $this->backups()->create('second');

        $this->assertSame($first['files'], $second['files']);
        $this->assertArrayNotHasKey('backups/first/manifest.json', array_flip(array_column(
            $this->backups()->manifest('second')['files'],
            'path',
        )));
    }

    public function test_tampering_with_a_backup_is_detected(): void
    {
        $this->engine()->create('users', ['name' => 'A'], 'usr_a');
        $this->backups()->create('first');

        $paths = $this->app->make(StoragePaths::class);
        $manifest = $this->backups()->manifest('first');
        $path = (string) $manifest['files'][0]['path'];

        file_put_contents($paths->backupTree('first').'/'.$path, 'tampered');

        $verify = $this->backups()->verify('first');

        $this->assertFalse($verify['ok']);
        $this->assertContains($path, $verify['mismatches']);
    }

    public function test_restoring_puts_the_backed_up_bytes_back(): void
    {
        $engine = $this->engine();
        $engine->create('users', ['name' => 'A'], 'usr_a');

        $paths = $this->app->make(StoragePaths::class);
        $record = $paths->record('users', 'usr_a');
        $original = (string) file_get_contents($record);

        $this->backups()->create('first');

        file_put_contents($record, 'broken');

        $result = $this->backups()->restore('first');

        $this->assertGreaterThan(0, $result['restored']);
        $this->assertSame($original, (string) file_get_contents($record));
        $this->assertSame('A', $this->engine()->read('users', 'usr_a')?->data['name']);
    }

    public function test_restoring_a_corrupted_backup_is_refused(): void
    {
        $this->engine()->create('users', ['name' => 'A'], 'usr_a');
        $this->backups()->create('first');

        $paths = $this->app->make(StoragePaths::class);
        $manifest = $this->backups()->manifest('first');
        $path = (string) $manifest['files'][0]['path'];

        file_put_contents($paths->backupTree('first').'/'.$path, 'tampered');

        $this->expectException(\RuntimeException::class);

        $this->backups()->restore('first');
    }

    public function test_pruning_keeps_the_newest_backups(): void
    {
        $manager = $this->backups();
        $manager->create('old');
        $manager->create('middle');
        $manager->create('new');

        $paths = $this->app->make(StoragePaths::class);

        foreach (['old' => '2026-01-01T00:00:00Z', 'middle' => '2026-02-01T00:00:00Z', 'new' => '2026-03-01T00:00:00Z'] as $name => $createdAt) {
            $manifest = $manager->manifest($name);
            $manifest['created_at'] = $createdAt;
            file_put_contents($paths->backupManifest($name), json_encode($manifest));
        }

        $removed = $manager->prune(1);

        $this->assertSame(['middle', 'old'], $removed);
        $this->assertSame('new', $manager->list()[0]['name']);
        $this->assertCount(1, $manager->list());
    }
}
