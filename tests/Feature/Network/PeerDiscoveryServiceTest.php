<?php

namespace Tests\Feature\Network;

use App\DStore\Monitoring\PeerStatusStore;
use App\DStore\Network\PeerDiscoveryService;
use App\DStore\Security\PeerRegistry;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PeerDiscoveryServiceTest extends TestCase
{
    private function discovery(): PeerDiscoveryService
    {
        config(['dstore.peers' => [
            'uae' => ['id' => 'uae', 'url' => 'http://127.0.0.1:8002'],
            'usa' => ['id' => 'usa', 'url' => 'http://127.0.0.1:8003'],
        ]]);
        $this->refreshDStoreBindings();

        return $this->app->make(PeerDiscoveryService::class);
    }

    public function test_a_reachable_peer_is_reported_as_online(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'node_id' => 'uae'], 200)]);

        $results = $this->discovery()->probe('uae');

        $this->assertTrue($results['uae']['reachable']);
        $this->assertSame('uae', $results['uae']['node_id']);
        $this->assertSame(
            PeerStatusStore::STATE_ONLINE,
            $this->app->make(PeerStatusStore::class)->state('uae'),
        );
    }

    public function test_an_unreachable_peer_is_recorded_as_unreachable(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $results = $this->discovery()->probe('uae');

        $this->assertFalse($results['uae']['reachable']);
        $this->assertSame(
            PeerStatusStore::STATE_UNREACHABLE,
            $this->app->make(PeerStatusStore::class)->state('uae'),
        );
    }

    public function test_the_reachability_survives_a_restart(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'node_id' => 'uae'], 200)]);

        $this->discovery()->probe('uae');

        $this->refreshDStoreBindings();

        $this->assertSame(
            PeerStatusStore::STATE_ONLINE,
            $this->app->make(PeerStatusStore::class)->state('uae'),
        );
    }

    public function test_a_peer_without_a_url_is_unreachable(): void
    {
        config(['dstore.peers' => ['nowhere' => ['id' => 'nowhere', 'url' => '']]]);
        $this->refreshDStoreBindings();

        $results = $this->app->make(PeerDiscoveryService::class)->probe('nowhere');

        $this->assertFalse($results['nowhere']['reachable']);
    }

    public function test_consecutive_failures_are_counted(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $statuses = $this->app->make(PeerStatusStore::class);

        $statuses->recordFailure('uae', 'first');
        $status = $statuses->recordFailure('uae', 'second');

        $this->assertSame(2, $status['consecutive_failures']);
    }

    public function test_a_success_resets_the_failure_counter(): void
    {
        $statuses = $this->app->make(PeerStatusStore::class);

        $statuses->recordFailure('uae', 'first');
        $status = $statuses->recordSuccess('uae', 12.5);

        $this->assertSame(0, $status['consecutive_failures']);
        $this->assertNull($status['last_error']);
        $this->assertNotNull($status['last_seen_at']);
    }
}
