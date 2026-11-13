<?php

namespace Tests\Feature\Monitoring;

use App\DStore\Monitoring\HeartbeatService;
use App\DStore\Monitoring\PeerStatusStore;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HeartbeatServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dstore.peers' => [
                'uae' => ['id' => 'uae', 'url' => 'http://127.0.0.1:8002'],
            ],
            'dstore.network.max_attempts' => 1,
            'dstore.replication.retry_base_delay' => 0,
            'dstore.replication.retry_max_delay' => 0,
        ]);

        $this->refreshDStoreBindings();
    }

    private function heartbeats(): HeartbeatService
    {
        return $this->app->make(HeartbeatService::class);
    }

    public function test_a_heartbeat_records_the_peer_and_its_epoch(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'node_id' => 'uae',
                'name' => 'UAE',
                'region' => 'middle-east',
                'url' => 'http://127.0.0.1:8002',
                'epoch' => 'epoch-a',
                'started_at' => '2026-11-13T00:00:00Z',
                'applied_vector' => ['uae' => 7],
                'records' => 4,
                'events_committed' => 7,
                'storage_bytes' => 4096,
                'degraded' => false,
                'time' => '2026-11-13T00:00:01Z',
            ], 200),
        ]);

        $result = $this->heartbeats()->beat('uae');

        $this->assertTrue($result->reachable);
        $this->assertSame(PeerStatusStore::STATE_ONLINE, $result->state);
        $this->assertSame(['uae' => 7], $result->appliedVector);
        $this->assertSame('epoch-a', $result->epoch);
        $this->assertFalse($result->restarted);

        $status = $this->app->make(PeerStatusStore::class)->get('uae');
        $this->assertSame('epoch-a', $status['epoch']);
        $this->assertSame(4, $status['records']);
        $this->assertSame(7, $status['events_committed']);
        $this->assertSame(4096, $status['storage_bytes']);
        $this->assertSame('UAE', $status['name']);
    }

    public function test_an_epoch_change_is_detected_as_a_restart(): void
    {
        Http::fakeSequence()
            ->push(['ok' => true, 'epoch' => 'epoch-a', 'applied_vector' => [], 'degraded' => false], 200)
            ->push(['ok' => true, 'epoch' => 'epoch-b', 'applied_vector' => [], 'degraded' => false], 200);

        $service = $this->heartbeats();

        $first = $service->beat('uae');
        $second = $service->beat('uae');

        $this->assertFalse($first->restarted);
        $this->assertTrue($second->restarted);
        $this->assertSame(1, $this->app->make(PeerStatusStore::class)->get('uae')['restarts']);
    }

    public function test_a_degraded_peer_is_recorded_as_degraded(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'epoch' => 'epoch-a',
                'degraded' => true,
                'applied_vector' => [],
            ], 200),
        ]);

        $result = $this->heartbeats()->beat('uae');

        $this->assertTrue($result->reachable);
        $this->assertSame(PeerStatusStore::STATE_DEGRADED, $result->state);
        $this->assertTrue((bool) $this->app->make(PeerStatusStore::class)->get('uae')['degraded']);
    }

    public function test_a_failed_heartbeat_is_recorded_as_a_failure(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'down'], 503),
        ]);

        $result = $this->heartbeats()->beat('uae');

        $this->assertFalse($result->reachable);
        $this->assertSame(PeerStatusStore::STATE_UNREACHABLE, $result->state);
        $this->assertStringContainsString('down', $result->error);

        $status = $this->app->make(PeerStatusStore::class)->get('uae');
        $this->assertSame(1, $status['consecutive_failures']);
        $this->assertSame('down', $status['last_error']);
    }

    public function test_a_peer_without_a_url_is_never_called(): void
    {
        config([
            'dstore.peers' => [
                'uae' => ['id' => 'uae', 'url' => ''],
            ],
        ]);
        $this->refreshDStoreBindings();

        Http::fake();

        $result = $this->heartbeats()->beat('uae');

        $this->assertFalse($result->reachable);
        $this->assertSame(PeerStatusStore::STATE_UNREACHABLE, $result->state);
        Http::assertNothingSent();
    }
}
