<?php

namespace Tests\Feature\Dashboard;

use App\DStore\Monitoring\FailureDetector;
use App\DStore\Monitoring\PeerStatusStore;
use App\DStore\Security\PeerRegistry;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardTest extends TestCase
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

    public function test_the_overview_page_renders_real_node_data(): void
    {
        $this->app->make(StorageEngineInterface::class)->create('users', ['name' => 'A'], 'usr_a');

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Network overview');
        $response->assertSee('test-node');
        $response->assertSee('uae');
        $response->assertSee('usa');
    }

    public function test_the_overview_json_contains_self_peers_and_summary(): void
    {
        $engine = $this->app->make(StorageEngineInterface::class);
        $engine->create('users', ['name' => 'A'], 'usr_a');
        $engine->create('products', ['name' => 'P'], 'prd_a');

        $this->app->make(PeerStatusStore::class)->recordHeartbeat('uae', 12.5, PeerStatusStore::STATE_ONLINE, [
            'epoch' => 'epoch-uae',
            'records' => 2,
            'events_committed' => 2,
            'storage_bytes' => 4096,
            'name' => 'UAE',
            'region' => 'middle-east',
        ]);

        $response = $this->getJson('/dashboard/data');

        $response->assertOk();
        $response->assertJsonPath('node.node_id', 'test-node');
        $response->assertJsonPath('node.records', 2);
        $response->assertJsonPath('summary.total', 3);
        $response->assertJsonPath('summary.records', 2);
        $response->assertJsonStructure([
            'node' => ['node_id', 'state', 'epoch', 'records', 'collections', 'pending', 'applied_vector'],
            'summary' => ['total', 'online', 'degraded', 'unreachable', 'offline', 'unknown', 'mesh'],
            'nodes',
            'conflicts',
            'backups',
            'recovery',
            'generated_at',
        ]);

        $uae = collect($response->json('nodes'))->firstWhere('node_id', 'uae');

        $this->assertSame('UAE', $uae['name']);
        $this->assertSame('online', $uae['state']);
        $this->assertSame('epoch-uae', $uae['epoch']);
        $this->assertSame(2, $uae['records']);
        $this->assertSame(4096, $uae['storage_bytes']);
    }

    public function test_the_overview_reflects_a_failure_detected_state(): void
    {
        $statuses = $this->app->make(PeerStatusStore::class);
        $statuses->recordFailure('uae', 'timeout');
        $statuses->recordFailure('uae', 'timeout');
        $statuses->recordFailure('uae', 'timeout');

        $this->app->make(FailureDetector::class)->refresh(['uae']);

        $response = $this->getJson('/dashboard/data');
        $uae = collect($response->json('nodes'))->firstWhere('node_id', 'uae');

        $this->assertSame('offline', $uae['state']);
        $this->assertFalse($uae['reachable']);
        $this->assertSame(3, $uae['consecutive_failures']);
        $this->assertSame('degraded', $response->json('summary.mesh'));
        $this->assertSame(1, $response->json('summary.offline'));
    }

    public function test_the_self_detail_page_lists_collections(): void
    {
        $engine = $this->app->make(StorageEngineInterface::class);
        $engine->create('users', ['name' => 'A'], 'usr_a');
        $engine->create('users', ['name' => 'B'], 'usr_b');

        $response = $this->get('/nodes/test-node');

        $response->assertOk();
        $response->assertSee('Identity');
        $response->assertSee('Collections on this node');
        $response->assertSee('users');
        $response->assertSee('Replication vectors');
    }

    public function test_a_peer_detail_page_renders_without_exposing_its_secret(): void
    {
        $key = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES));
        $this->app->make(PeerRegistry::class)->approve('uae', $key, 'http://127.0.0.1:8002', 'UAE', 'middle-east');

        $this->app->make(PeerStatusStore::class)->recordHeartbeat('uae', 8.0, PeerStatusStore::STATE_ONLINE, [
            'epoch' => 'epoch-uae',
            'records' => 4,
            'events_committed' => 7,
            'storage_bytes' => 2048,
            'name' => 'UAE',
            'region' => 'middle-east',
        ]);

        $response = $this->get('/nodes/uae');

        $response->assertOk();
        $response->assertSee('UAE');
        $response->assertSee('Probe now');
        $response->assertSee('epoch-uae');
        $response->assertDontSee($key);
    }

    public function test_an_unknown_node_is_a_404(): void
    {
        $this->get('/nodes/nowhere')->assertNotFound();
        $this->getJson('/dashboard/data/nodes/nowhere')->assertNotFound();
    }

    public function test_the_node_json_endpoint_returns_the_requested_node(): void
    {
        $this->getJson('/dashboard/data/nodes/test-node')
            ->assertOk()
            ->assertJsonPath('node.node_id', 'test-node');

        $this->getJson('/dashboard/data/nodes/uae')
            ->assertOk()
            ->assertJsonPath('node.node_id', 'uae')
            ->assertJsonPath('node.trust', PeerRegistry::STATUS_PENDING);
    }

    public function test_probing_a_peer_updates_its_status(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'node_id' => 'uae',
                'name' => 'UAE',
                'region' => 'middle-east',
                'epoch' => 'epoch-probe',
                'started_at' => '2026-11-13T00:00:00Z',
                'degraded' => false,
                'applied_vector' => ['uae' => 3],
                'records' => 5,
                'events_committed' => 6,
                'storage_bytes' => 1024,
                'time' => '2026-11-13T00:00:01Z',
            ], 200),
        ]);

        $response = $this->postJson('/dashboard/probe/uae');

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('node.state', PeerStatusStore::STATE_ONLINE);
        $response->assertJsonPath('node.epoch', 'epoch-probe');
        $response->assertJsonPath('heartbeat.reachable', true);

        $this->assertSame('epoch-probe', $this->app->make(PeerStatusStore::class)->get('uae')['epoch']);
    }

    public function test_probing_the_local_node_is_refused(): void
    {
        $this->postJson('/dashboard/probe/test-node')->assertStatus(422);
    }

    public function test_probing_an_unknown_node_is_a_404(): void
    {
        $this->postJson('/dashboard/probe/nowhere')->assertNotFound();
    }
}
