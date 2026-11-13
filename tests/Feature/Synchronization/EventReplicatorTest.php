<?php

namespace Tests\Feature\Synchronization;

use App\DStore\Replication\EventReplicator;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\EventLog;
use App\DStore\Synchronization\DeliveryTracker;
use App\DStore\Synchronization\SyncCursor;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsPeerEvents;
use Tests\TestCase;

class EventReplicatorTest extends TestCase
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

    private function replicator(): EventReplicator
    {
        return $this->app->make(EventReplicator::class);
    }

    private function tracker(): DeliveryTracker
    {
        return $this->app->make(DeliveryTracker::class);
    }

    private function createLocalRecord(): void
    {
        $this->app->make(StorageEngineInterface::class)->create('users', ['name' => 'Local'], 'usr_1');
    }

    public function test_a_local_write_is_queued_for_the_peer(): void
    {
        $this->createLocalRecord();

        $this->assertSame(1, $this->app->make(EventLog::class)->pendingCount('uae'));
    }

    public function test_an_event_is_delivered_when_the_peer_confirms_it_in_its_applied_vector(): void
    {
        $this->createLocalRecord();

        Http::fake(['*' => Http::response(['ok' => true, 'applied_vector' => ['test-node' => 1]], 200)]);

        $result = $this->replicator()->push('uae');

        $this->assertTrue($result->successful);
        $this->assertSame(1, $result->pushed);
        $this->assertSame(1, $result->delivered);
        $this->assertSame(0, $result->pending);

        // The pending queue is only cleared by a confirmation.
        $this->assertSame(0, $this->app->make(EventLog::class)->pendingCount('uae'));
    }

    public function test_a_successful_response_that_does_not_confirm_the_event_keeps_it_pending(): void
    {
        $this->createLocalRecord();

        // The peer answers 200 but its applied vector does not mention our
        // event, so the data is not synchronised yet.
        Http::fake(['*' => Http::response(['ok' => true, 'applied_vector' => []], 200)]);

        $result = $this->replicator()->push('uae');

        $this->assertTrue($result->successful);
        $this->assertSame(1, $result->pushed);
        $this->assertSame(0, $result->delivered);
        $this->assertSame(1, $result->pending);

        $this->assertSame(1, $this->app->make(EventLog::class)->pendingCount('uae'));
    }

    public function test_a_failing_peer_keeps_the_event_pending_and_records_the_error(): void
    {
        $this->createLocalRecord();

        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $result = $this->replicator()->push('uae');

        $this->assertFalse($result->successful);
        $this->assertSame(1, $result->pending);
        $this->assertStringContainsString('boom', $result->error);

        $this->assertSame(1, $this->app->make(EventLog::class)->pendingCount('uae'));

        $cursor = $this->tracker()->cursor('uae');

        $this->assertSame(SyncCursor::STATE_UNREACHABLE, $cursor->state);
        $this->assertSame(1, $cursor->attempts);
        $this->assertStringContainsString('boom', (string) $cursor->lastError);
    }

    public function test_an_unreachable_peer_is_reported_as_unreachable(): void
    {
        $this->createLocalRecord();

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $result = $this->replicator()->push('uae');

        $this->assertFalse($result->successful);
        $this->assertStringContainsString('Connection refused', $result->error);
        $this->assertSame(1, $this->app->make(EventLog::class)->pendingCount('uae'));
    }

    public function test_there_is_nothing_to_push_when_the_queue_is_empty(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'applied_vector' => []], 200)]);

        $result = $this->replicator()->push('uae');

        $this->assertTrue($result->successful);
        $this->assertSame(0, $result->pushed);
    }

    public function test_the_checkpoint_survives_a_restart(): void
    {
        $this->createLocalRecord();

        Http::fake(['*' => Http::response(['ok' => true, 'applied_vector' => ['test-node' => 1]], 200)]);

        $this->replicator()->push('uae');

        // A fresh tracker reads the checkpoint from disk, the way a restarted
        // process would.
        $cursor = (new DeliveryTracker($this->app->make(EventLog::class)))->cursor('uae');

        $this->assertSame(['test-node' => 1], $cursor->applied);
        $this->assertSame(['test-node' => 1], $cursor->delivered);
        $this->assertSame(0, $cursor->pendingCount);
        $this->assertNotNull($cursor->lastSyncAt);
    }

    public function test_the_push_carries_the_events_in_the_pending_queue(): void
    {
        $this->createLocalRecord();

        Http::fake(['*' => Http::response(['ok' => true, 'applied_vector' => ['test-node' => 1]], 200)]);

        $this->replicator()->push('uae');

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            if (! str_contains($request->url(), '/api/dstore/v1/events/push')) {
                return false;
            }

            $body = $request->data();

            return isset($body['envelope']['payload']['events'])
                && count($body['envelope']['payload']['events']) === 1;
        });
    }
}
