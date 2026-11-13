<?php

namespace Tests\Feature\Console;

use App\DStore\Storage\Contracts\StorageEngineInterface;
use Tests\TestCase;

class PhaseSevenCommandsTest extends TestCase
{
    public function test_backup_create_list_verify_and_restore_commands(): void
    {
        $this->app->make(StorageEngineInterface::class)->create('users', ['name' => 'A'], 'usr_a');

        $this->artisan('dstore:backup', ['--name' => 'cmd-backup', '--json' => true])
            ->assertExitCode(0);

        $this->artisan('dstore:backup:list', ['--json' => true])
            ->assertExitCode(0);

        $this->artisan('dstore:backup:verify', ['name' => 'cmd-backup', '--json' => true])
            ->assertExitCode(0);

        $this->artisan('dstore:backup:restore', ['name' => 'cmd-backup', '--force' => true])
            ->assertExitCode(0);
    }

    public function test_backup_restore_requires_force(): void
    {
        $this->artisan('dstore:backup', ['--name' => 'cmd-backup'])
            ->assertExitCode(0);

        $this->artisan('dstore:backup:restore', ['name' => 'cmd-backup'])
            ->assertExitCode(1);
    }

    public function test_index_rebuild_command(): void
    {
        $this->app->make(StorageEngineInterface::class)->create('users', ['name' => 'A']);

        $this->artisan('dstore:index:rebuild', ['--json' => true])
            ->assertExitCode(0);
    }

    public function test_tombstone_gc_command(): void
    {
        $engine = $this->app->make(StorageEngineInterface::class);
        $engine->create('users', ['name' => 'A'], 'usr_a');
        $engine->delete('users', 'usr_a');

        $this->artisan('dstore:gc:tombstones', ['--days' => 0, '--dry-run' => true])
            ->assertExitCode(0);
    }

    public function test_recover_command(): void
    {
        $this->app->make(StorageEngineInterface::class)->create('users', ['name' => 'A'], 'usr_a');

        $this->artisan('dstore:recover', [
            '--no-sync' => true,
            '--no-snapshot' => true,
            '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_monitor_command_without_peers(): void
    {
        config(['dstore.peers' => []]);
        $this->refreshDStoreBindings();

        $this->artisan('dstore:monitor', ['--once' => true, '--no-gossip' => true])
            ->assertExitCode(0);
    }
}
