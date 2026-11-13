<?php

namespace Tests\Feature\Synchronization;

use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\EventLog;
use App\DStore\Synchronization\SyncCursor;
use App\DStore\Synchronization\SyncEngine;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsPeerEvents;
use Tests\TestCase;

class SyncEngineTest extends TestCase
{
    use BuildsPeerEvents;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dstore.peers' => [
                'uae' => ['url' => 'http://127.0.0.1:8002'],
                'usa' => ['url' => 'http://127.0.0.1:8003'],
            ],
            'dstore.network.max_attempts' => 1,
            'dstore.replication.retry_base_delay' => 0,
            'dstore.replication.retry_max_delay' => 0,
        ]);

        $this->refreshDStoreBindings();

        $this->approvePeer($this->peerKeyManager('peer-uae'), 'uae');
        $this->approvePeer($this->peerKeyManager('peer-usa'), 'usa');
    }

    private function engine(): SyncEngine
    {
        return $this->app->make(SyncEngine::class);
    }

    public function test_a_pull_applies_the_events_the_peer_sends(): void
    {
        $peer = $this->peerKeyManager('peer-uae');
        $event = $this->signedEvent($peer);

        Http::fake([
            '*/api/dstore/v1/events/push' => Http::response(['ok' => true, 'applied_vector' => ['test-node' => 0]], 200),
            '*/api/dstore/v1/events*' => Http::response(['ok' => true, 'events' => [$event->toArray()]], 200),
            '*/api/dstore/v1/heartbeat' => Http::response(['ok' => true, 'applied_vector' => ['uae' => 1]], 200),
        ]);

        $report = $this->engine()->sync('uae');

        $this->assertTrue($report->reachable);
        $this->assertSame(1, $report->pulled);
        $this->assertSame(1, $report->applied);
        $this->assertSame(0, $report->rejected);

        $record = $this->app->make(StorageEngineInterface::class)->read('users', 'usr_remote_1');

        $this->assertNotNull($record);
        $this->assertSame(['name' => 'Remote'], $record->data);
    }

    public function test_a_pulled_event_is_kept_in_the_local_committed_log(): void
    {
        $peer = $this->peerKeyManager('peer-uae');
        $event = $this->signedEvent($peer);

        Http::fake([
            '*/api/dstore/v1/events/push' => Http::response(['ok' => true, 'applied_vector' => ['test-node' => 0]], 200),
            '*/api/dstore/v1/events*' => Http::response(['ok' => true, 'events' => [$event->toArray()]], 200),
            '*/api/dstore/v1/heartbeat' => Http::response(['ok' => true, 'applied_vector' => ['uae' => 1]], 200),
        ]);

        $this->engine()->sync('uae');

        // The node advertised the event in its applied vector, so it must be
        // able to serve it to the rest of the mesh.
        $this->assertCount(1, iterator_to_array($this->app->make(EventLog::class)->all()));
        $this->assertSame(['uae' => 1], $this->app->make(EventLog::class)->appliedVector());
    }

    public function test_a_pulled_event_is_queued_for_the_other_peers(): void
    {
        $peer = $this->peerKeyManager('peer-uae');
        $event = $this->signedEvent($peer);

        Http::fake([
            '*/api/dstore/v1/events/push' => Http::response(['ok' => true, 'applied_vector' => ['test-node' => 0]], 200),
            '*/api/dstore/v1/events*' => Http::response(['ok' => true, 'events' => [$event->toArray()]], 200),
            '*/api/dstore/v1/heartbeat' => Http::response(['ok' => true, 'applied_vector' => ['uae' => 1]], 200),
        ]);

        $this->engine()->sync('uae');

        $events = $this->app->make(EventLog::class);

        $this->assertSame(0, $events->pendingCount('uae'));
        $this->assertSame(1, $events->pendingCount('usa'));
    }

    public function test_a_forged_event_from_a_peer_is_not_applied(): void
    {
        $peer = $this->peerKeyManager('peer-uae');
        $event = $this->signedEvent($peer);

        $forged = $event->toArray();
        $forged['payload']['data']['name'] = 'Mallory';

        Http::fake([
            '*/api/dstore/v1/events/push' => Http::response(['ok' => true, 'applied_vector' => ['test-node' => 0]], 200),
            '*/api/dstore/v1/events*' => Http::response(['ok' => true, 'events' => [$forged]], 200),
            '*/api/dstore/v1/heartbeat' => Http::response(['ok' => true, 'applied_vector' => ['uae' => 1]], 200),
        ]);

        $report = $this->engine()->sync('uae');

        $this->assertSame(1, $report->pulled);
        $this->assertSame(0, $report->applied);
        $this->assertSame(1, $report->rejected);

        $this->assertNull($this->app->make(StorageEngineInterface::class)->read('users', 'usr_remote_1'));

        // A forged event must not reach the committed log either.
        $this->assertCount(0, iterator_to_array($this->app->make(EventLog::class)->all()));
    }

    public function test_the_pull_asks_only_for_the_events_the_node_is_missing(): void
    {
        Http::fake([
            '*/api/dstore/v1/events/push' => Http::response(['ok' => true, 'applied_vector' => ['test-node' => 0]], 200),
            '*/api/dstore/v1/events*' => Http::response(['ok' => true, 'events' => []], 200),
            '*/api/dstore/v1/heartbeat' => Http::response(['ok' => true, 'applied_vector' => ['uae' => 1]], 200),
        ]);

        $this->engine()->sync('uae');

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            if (! str_contains($request->url(), '/api/dstore/v1/events?')) {
                return false;
            }

            return str_contains($request->url(), 'after=');
        });
    }

    public function test_an_unreachable_peer_is_reported_without_pulling(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $report = $this->engine()->sync('uae');

        $this->assertFalse($report->reachable);
        $this->assertSame(0, $report->pulled);
        $this->assertStringContainsString('Connection refused', $report->error);
    }

    public function test_the_cursor_records_the_peer_state_after_a_heartbeat(): void
    {
        Http::fake([
            '*/api/dstore/v1/events/push' => Http::response(['ok' => true, 'applied_vector' => ['test-node' => 0]], 200),
            '*/api/dstore/v1/events*' => Http::response(['ok' => true, 'events' => []], 200),
            '*/api/dstore/v1/heartbeat' => Http::response(['ok' => true, 'applied_vector' => ['uae' => 5]], 200),
        ]);

        $this->engine()->sync('uae');

        $cursor = $this->app->make(\App\DStore\Synchronization\DeliveryTracker::class)->cursor('uae');

        $this->assertSame(['uae' => 5], $cursor->applied);
        $this->assertNotNull($cursor->lastHeartbeatAt);
        $this->assertContains($cursor->state, [SyncCursor::STATE_BEHIND, SyncCursor::STATE_IN_SYNC]);
    }

    public function test_a_sync_moves_both_directions(): void
    {
        $peer = $this->peerKeyManager('peer-uae');
        $incoming = $this->signedEvent($peer);

        // A local write that the peer does not have yet.
        $this->app->make(StorageEngineInterface::class)->create('users', ['name' => 'Local'], 'usr_local');

        Http::fake([
            '*/api/dstore/v1/events/push' => Http::response(['ok' => true, 'applied_vector' => ['test-node' => 1]], 200),
            '*/api/dstore/v1/events*' => Http::response(['ok' => true, 'events' => [$incoming->toArray()]], 200),
            '*/api/dstore/v1/heartbeat' => Http::response(['ok' => true, 'applied_vector' => ['uae' => 1]], 200),
        ]);

        $report = $this->engine()->sync('uae');

        $this->assertSame(1, $report->applied);
        $this->assertSame(1, $report->pushed);
        $this->assertSame(1, $report->delivered);

        $engine = $this->app->make(StorageEngineInterface::class);

        $this->assertNotNull($engine->read('users', 'usr_remote_1'));
        $this->assertNotNull($engine->read('users', 'usr_local'));
    }
}
