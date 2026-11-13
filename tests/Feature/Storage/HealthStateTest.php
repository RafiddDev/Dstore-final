<?php

namespace Tests\Feature\Storage;

use App\DStore\Storage\HealthState;
use App\DStore\Storage\StoragePaths;
use Tests\TestCase;

class HealthStateTest extends TestCase
{
    public function test_a_fresh_node_is_not_degraded(): void
    {
        $this->assertFalse($this->app->make(HealthState::class)->isDegraded());
        $this->assertSame([], $this->app->make(HealthState::class)->issues());
    }

    public function test_degraded_state_survives_a_process_restart(): void
    {
        $this->app->make(HealthState::class)->markCorrupted('records/users/usr_1.json', 'hash mismatch');

        $this->assertTrue($this->app->make(HealthState::class)->isDegraded());
        $this->assertFileExists($this->storagePath('metadata/health.json'));

        $restarted = new HealthState($this->app->make(StoragePaths::class));

        $this->assertTrue($restarted->isDegraded());
        $this->assertSame('hash mismatch', $restarted->issues()[0]['reason']);
        $this->assertSame('records/users/usr_1.json', $restarted->issues()[0]['path']);
    }

    public function test_multiple_issues_are_all_recorded(): void
    {
        $health = $this->app->make(HealthState::class);
        $health->markCorrupted('records/users/usr_1.json', 'hash mismatch');
        $health->markCorrupted('records/products/prd_1.json', 'invalid json document');

        $restarted = new HealthState($this->app->make(StoragePaths::class));

        $this->assertCount(2, $restarted->issues());
        $this->assertSame('invalid json document', $restarted->issues()[1]['reason']);
    }

    public function test_clearing_issues_removes_the_degraded_state(): void
    {
        $health = $this->app->make(HealthState::class);
        $health->markCorrupted('records/users/usr_1.json', 'hash mismatch');
        $health->clear();

        $restarted = new HealthState($this->app->make(StoragePaths::class));

        $this->assertFalse($restarted->isDegraded());
        $this->assertSame([], $restarted->issues());
    }
}
