<?php

namespace Tests\Feature\Console;

use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\RecordStore;
use Tests\TestCase;

class RecordRestoreCommandTest extends TestCase
{
    private function engine(): StorageEngineInterface
    {
        return $this->app->make(StorageEngineInterface::class);
    }

    private function recordPath(string $recordId): string
    {
        return $this->storagePath('records/users/'.substr(hash('sha256', $recordId), 0, 2).'/'.$recordId.'.json');
    }

    public function test_a_lost_record_is_restored_from_the_log(): void
    {
        $this->engine()->create('users', ['name' => 'Survivor'], 'usr_x');

        unlink($this->recordPath('usr_x'));

        $this->artisan('dstore:records:restore')
            ->expectsOutputToContain('Restored:')
            ->assertSuccessful();

        $record = $this->engine()->read('users', 'usr_x');

        $this->assertNotNull($record);
        $this->assertSame(['name' => 'Survivor'], $record->data);
    }

    public function test_the_check_mode_reports_what_is_missing_without_restoring(): void
    {
        $this->engine()->create('users', ['name' => 'Survivor'], 'usr_x');

        unlink($this->recordPath('usr_x'));

        $this->artisan('dstore:records:restore', ['--check' => true])
            ->expectsOutputToContain('Records missing from the store: 1')
            ->assertFailed();

        $this->assertNull($this->engine()->read('users', 'usr_x'));
    }

    public function test_the_check_mode_succeeds_when_nothing_is_missing(): void
    {
        $this->engine()->create('users', ['name' => 'Survivor'], 'usr_x');

        $this->artisan('dstore:records:restore', ['--check' => true])
            ->expectsOutputToContain('Records missing from the store: 0')
            ->assertSuccessful();
    }

    public function test_the_reconcile_command_backfills_the_writer(): void
    {
        $this->engine()->create('users', ['name' => 'Legacy'], 'usr_x');

        $current = $this->engine()->read('users', 'usr_x');
        $this->assertNotNull($current);

        $this->app->make(RecordStore::class)->write($current->withWriter(''));

        $this->artisan('dstore:records:reconcile')
            ->expectsOutputToContain('Reconciled:')
            ->assertSuccessful();

        $record = $this->engine()->read('users', 'usr_x');

        $this->assertNotNull($record);
        $this->assertSame('test-node', $record->meta['writer']);
    }

    public function test_the_reconcile_check_mode_does_not_change_anything(): void
    {
        $this->engine()->create('users', ['name' => 'Legacy'], 'usr_x');

        $current = $this->engine()->read('users', 'usr_x');
        $this->assertNotNull($current);

        $this->app->make(RecordStore::class)->write($current->withWriter(''));

        $this->artisan('dstore:records:reconcile', ['--check' => true])
            ->expectsOutputToContain('Would be reconciled:')
            ->assertFailed();

        $record = $this->engine()->read('users', 'usr_x');

        $this->assertNotNull($record);
        $this->assertSame('', (string) $record->meta['writer']);
    }
}
