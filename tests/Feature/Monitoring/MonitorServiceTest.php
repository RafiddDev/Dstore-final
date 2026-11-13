<?php

namespace Tests\Feature\Monitoring;

use App\DStore\Monitoring\MonitorService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitorServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dstore.peers' => [
                'uae' => ['id' => 'uae', 'url' => 'http://127.0.0.1:8002'],
                'usa' => ['id' => 'usa', 'url' => 'http://127.0.0.1:8003'],
            ],
            'dstore.network.max_attempts' => 1,
            'dstore.replication.retry_base_delay' => 0,
            'dstore.replication.retry_max_delay' => 0,
        ]);

        $this->refreshDStoreBindings();
    }

    public function test_one_round_heartbeats_every_peer_and_reports_self(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '8002')) {
                return Http::response([
                    'ok' => true,
                    'node_id' => 'uae',
                    'epoch' => 'epoch-uae',
                    'degraded' => false,
                    'applied_vector' => ['uae' => 2],
                ], 200);
            }

            if (str_contains($request->url(), '8003')) {
                return Http::response([
                    'ok' => true,
                    'node_id' => 'usa',
                    'epoch' => 'epoch-usa',
                    'degraded' => true,
                    'applied_vector' => ['usa' => 3],
                ], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $report = $this->app->make(MonitorService::class)->run(true);

        $this->assertSame('test-node', $report['self']['node_id']);
        $this->assertSame(2, $report['total']);
        $this->assertSame(1, $report['online']);
        $this->assertSame(2, $report['gossiped']);
        $this->assertSame('degraded', $report['peers']['usa']['detected']['state']);
        $this->assertSame('online', $report['peers']['uae']['detected']['state']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/nodes/status'));
    }

    public function test_a_failed_peer_does_not_stop_the_round(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '8002')) {
                return Http::response(['error' => 'down'], 503);
            }

            return Http::response([
                'ok' => true,
                'node_id' => 'usa',
                'epoch' => 'epoch-usa',
                'degraded' => false,
                'applied_vector' => [],
            ], 200);
        });

        $report = $this->app->make(MonitorService::class)->run(false);

        $this->assertSame(2, $report['total']);
        $this->assertSame(1, $report['online']);
        $this->assertFalse($report['peers']['uae']['reachable']);
        $this->assertTrue($report['peers']['usa']['reachable']);
        $this->assertSame(0, $report['gossiped']);
    }

    public function test_monitor_with_no_peers_still_reports_the_local_node(): void
    {
        config(['dstore.peers' => []]);
        $this->refreshDStoreBindings();

        Http::fake();

        $report = $this->app->make(MonitorService::class)->run(true);

        $this->assertSame(0, $report['total']);
        $this->assertSame('test-node', $report['self']['node_id']);
        $this->assertSame('online', $report['self']['state']);
        Http::assertNothingSent();
    }
}
