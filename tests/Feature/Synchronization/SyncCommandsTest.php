<?php

namespace Tests\Feature\Synchronization;

use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsPeerEvents;
use Tests\TestCase;

class SyncCommandsTest extends TestCase
{
    use BuildsPeerEvents;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dstore.peers' => ['uae' => ['url' => 'http://127.0.0.1:8002']],
            'dstore.network.max_attempts' => 1,
            'dstore.replication.retry_base_delay' => 0,
            'dstore.replication.retry_max_delay' => 0,
        ]);

        $this->refreshDStoreBindings();

        $this->approvePeer($this->peerKeyManager(), 'uae');
    }

    public function test_the_status_command_reports_every_peer(): void
    {
        $this->artisan('dstore:sync:status')
            ->expectsOutputToContain('uae')
            ->expectsOutputToContain('Local applied vector')
            ->assertSuccessful();
    }

    public function test_the_status_command_works_without_peers(): void
    {
        config(['dstore.peers' => []]);
        $this->refreshDStoreBindings();

        $this->app->make(\App\DStore\Security\PeerRegistry::class)->forget('uae');

        $this->artisan('dstore:sync:status')
            ->expectsOutputToContain('No peers are configured')
            ->assertSuccessful();
    }

    public function test_the_run_command_reports_a_successful_pass(): void
    {
        Http::fake([
            '*/api/dstore/v1/events/push' => Http::response(['ok' => true, 'applied_vector' => ['test-node' => 0]], 200),
            '*/api/dstore/v1/events*' => Http::response(['ok' => true, 'events' => []], 200),
            '*/api/dstore/v1/heartbeat' => Http::response(['ok' => true, 'applied_vector' => ['uae' => 0]], 200),
        ]);

        $this->artisan('dstore:sync:run')
            ->expectsOutputToContain('uae')
            ->assertSuccessful();
    }

    public function test_the_run_command_fails_when_a_peer_is_unreachable(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $this->artisan('dstore:sync:run')
            ->expectsOutputToContain('unreachable')
            ->assertFailed();
    }

    public function test_the_daemon_runs_a_single_pass(): void
    {
        Http::fake([
            '*/api/dstore/v1/events/push' => Http::response(['ok' => true, 'applied_vector' => ['test-node' => 0]], 200),
            '*/api/dstore/v1/events*' => Http::response(['ok' => true, 'events' => []], 200),
            '*/api/dstore/v1/heartbeat' => Http::response(['ok' => true, 'applied_vector' => ['uae' => 0]], 200),
        ]);

        $this->artisan('dstore:sync:daemon', ['--once' => true])
            ->expectsOutputToContain('uae')
            ->assertSuccessful();
    }

    public function test_the_daemon_does_nothing_without_peers(): void
    {
        config(['dstore.peers' => []]);
        $this->refreshDStoreBindings();

        $this->app->make(\App\DStore\Security\PeerRegistry::class)->forget('uae');

        $this->artisan('dstore:sync:daemon', ['--once' => true])
            ->expectsOutputToContain('No peers are configured')
            ->assertSuccessful();
    }
}
