<?php

namespace Tests\Feature\Synchronization;

use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\Event;
use App\DStore\Storage\EventLog;
use App\DStore\Storage\RecordDocument;
use App\DStore\Synchronization\ApplyOutcome;
use App\DStore\Synchronization\EventApplier;
use Tests\Support\BuildsPeerEvents;
use Tests\TestCase;

class EventApplierTest extends TestCase
{
    use BuildsPeerEvents;

    private function applier(): EventApplier
    {
        return $this->app->make(EventApplier::class);
    }

    private function engine(): StorageEngineInterface
    {
        return $this->app->make(StorageEngineInterface::class);
    }

    public function test_an_unknown_record_is_created_from_the_event(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $outcome = $this->applier()->apply($this->signedEvent($peer));

        $this->assertSame(ApplyOutcome::APPLIED, $outcome->status);

        $record = $this->engine()->read('users', 'usr_remote_1');

        $this->assertNotNull($record);
        $this->assertSame(['name' => 'Remote'], $record->data);
        $this->assertSame(['uae' => 1], $record->version());
        $this->assertSame('uae', $record->writer());
    }

    public function test_the_applied_event_is_marked_in_the_applied_index(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $event = $this->signedEvent($peer);

        $this->applier()->apply($event);

        $this->assertTrue($this->app->make(EventLog::class)->hasApplied($event->eventId));
        $this->assertSame(['uae' => 1], $this->app->make(EventLog::class)->appliedVector());
    }

    public function test_the_same_event_applied_twice_is_a_duplicate(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $event = $this->signedEvent($peer);

        $this->applier()->apply($event);
        $outcome = $this->applier()->apply($event);

        $this->assertSame(ApplyOutcome::DUPLICATE, $outcome->status);
    }

    public function test_a_newer_version_replaces_the_local_data(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $this->applier()->apply($this->signedEvent($peer, 'uae', 1, 'users', 'usr_remote_1', ['name' => 'First'], ['uae' => 1]));

        $outcome = $this->applier()->apply(
            $this->signedEvent($peer, 'uae', 2, 'users', 'usr_remote_1', ['name' => 'Second'], ['uae' => 2], '2026-11-12T11:00:00Z', Event::TYPE_UPDATED)
        );

        $this->assertSame(ApplyOutcome::APPLIED, $outcome->status);

        $record = $this->engine()->read('users', 'usr_remote_1');

        $this->assertSame(['name' => 'Second'], $record->data);
        $this->assertSame(['uae' => 2], $record->version());
    }

    public function test_a_stale_version_does_not_overwrite_newer_local_data(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $this->applier()->apply($this->signedEvent($peer, 'uae', 1, 'users', 'usr_remote_1', ['name' => 'First'], ['uae' => 1]));

        // A different node already moved the record further ahead.
        $local = $this->engine()->read('users', 'usr_remote_1');
        $this->assertNotNull($local);

        $stale = $this->signedEvent($peer, 'uae', 2, 'users', 'usr_remote_1', ['name' => 'Old'], ['uae' => 1], '2026-11-12T11:00:00Z', Event::TYPE_UPDATED);
        $this->app->make(EventLog::class)->markApplied($stale->eventId);

        // Bring the local record past the incoming version.
        $newer = $this->signedEvent($peer, 'uae', 3, 'users', 'usr_remote_1', ['name' => 'Newest'], ['uae' => 9], '2026-11-12T12:00:00Z', Event::TYPE_UPDATED);
        $this->applier()->apply($newer);

        $record = $this->engine()->read('users', 'usr_remote_1');

        $this->assertSame(['name' => 'Newest'], $record->data);
    }

    public function test_a_stale_event_is_reported_as_stale_and_keeps_the_local_data(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $this->applier()->apply($this->signedEvent($peer, 'uae', 1, 'users', 'usr_remote_1', ['name' => 'First'], ['uae' => 5]));

        $outcome = $this->applier()->apply(
            $this->signedEvent($peer, 'uae', 2, 'users', 'usr_remote_1', ['name' => 'Old'], ['uae' => 2], '2026-11-12T11:00:00Z', Event::TYPE_UPDATED)
        );

        $this->assertSame(ApplyOutcome::STALE, $outcome->status);

        $record = $this->engine()->read('users', 'usr_remote_1');

        $this->assertSame(['name' => 'First'], $record->data);
        $this->assertSame(['uae' => 5], $record->version());
    }

    public function test_concurrent_writes_are_resolved_and_both_versions_survive(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        // The local node wrote version {test-node: 1}.
        $this->engine()->create('users', ['name' => 'Local'], 'usr_remote_1');

        // The peer wrote {uae: 1} concurrently: neither dominates.
        $outcome = $this->applier()->apply(
            $this->signedEvent($peer, 'uae', 1, 'users', 'usr_remote_1', ['name' => 'Remote'], ['uae' => 1])
        );

        $this->assertSame(ApplyOutcome::CONFLICT, $outcome->status);

        $record = $this->engine()->read('users', 'usr_remote_1');

        // The merged version dominates both sides.
        $this->assertSame(['test-node' => 1, 'uae' => 1], $record->version());

        // The overridden side is kept in the history, not thrown away.
        $this->assertCount(1, $record->history());
    }

    public function test_the_conflict_decision_is_the_same_whatever_the_argument_order(): void
    {
        $resolver = $this->app->make(\App\DStore\ConflictResolution\ConflictResolver::class);

        // The timestamps are deliberately absurd: the decision must not look
        // at a clock, only at the version vectors.
        $local = RecordDocument::make('users', 'usr_remote_1', ['name' => 'Local'], ['test-node' => 1], '2026-11-12T10:00:00Z');
        $incoming = RecordDocument::make('users', 'usr_remote_1', ['name' => 'Remote'], ['uae' => 1], '2030-01-01T00:00:00Z');

        $resolved = $resolver->resolve($local, $incoming);
        $swapped = $resolver->resolve($incoming, $local);

        // Two nodes that hold the same pair in opposite roles must pick the
        // same winner, or the mesh would diverge.
        $this->assertSame($resolved->winner->data, $swapped->winner->data);
        $this->assertSame(['name' => 'Remote'], $resolved->winner->data);
        $this->assertSame(['name' => 'Local'], $resolved->loser->data);
    }

    public function test_a_longer_version_vector_wins_over_a_greater_node_id(): void
    {
        $resolver = $this->app->make(\App\DStore\ConflictResolution\ConflictResolver::class);

        $local = RecordDocument::make('users', 'usr_remote_1', ['name' => 'Local'], ['uae' => 3], '2026-11-12T10:00:00Z');
        $incoming = RecordDocument::make('users', 'usr_remote_1', ['name' => 'Remote'], ['zzz' => 1], '2030-01-01T00:00:00Z');

        $resolved = $resolver->resolve($local, $incoming);

        $this->assertSame(['name' => 'Local'], $resolved->winner->data);
    }

    public function test_an_event_from_an_untrusted_origin_is_rejected(): void
    {
        $peer = $this->peerKeyManager();

        $outcome = $this->applier()->apply($this->signedEvent($peer, 'uae', 1));

        $this->assertSame(ApplyOutcome::REJECTED, $outcome->status);
        $this->assertSame('origin node is not a trusted peer', $outcome->reason);
        $this->assertNull($this->engine()->read('users', 'usr_remote_1'));
    }

    public function test_an_event_with_a_tampered_payload_is_rejected(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $event = $this->signedEvent($peer);
        $tampered = new Event(
            $event->eventId,
            $event->seq,
            $event->origin,
            $event->type,
            $event->collection,
            $event->recordId,
            ['data' => ['name' => 'Mallory']],
            $event->originClock,
            $event->createdAt,
            $event->prevEventId,
            $event->signature,
        );

        $outcome = $this->applier()->apply($tampered);

        $this->assertSame(ApplyOutcome::REJECTED, $outcome->status);
        $this->assertSame('invalid event signature', $outcome->reason);
        $this->assertNull($this->engine()->read('users', 'usr_remote_1'));
    }

    public function test_a_deleted_record_is_not_resurrected_by_a_stale_create(): void
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        $this->applier()->apply($this->signedEvent($peer, 'uae', 1, 'users', 'usr_remote_1', ['name' => 'First'], ['uae' => 3]));

        $this->engine()->delete('users', 'usr_remote_1');

        $record = $this->engine()->read('users', 'usr_remote_1');

        $this->assertNotNull($record);
        $this->assertTrue($record->isDeleted());
        $this->assertCount(0, $this->engine()->all('users'));
        $this->assertTrue($this->app->make(\App\DStore\Storage\TombstoneStore::class)->has('usr_remote_1'));

        // A create that is older than the delete must not bring it back.
        $outcome = $this->applier()->apply(
            $this->signedEvent($peer, 'uae', 2, 'users', 'usr_remote_1', ['name' => 'Ghost'], ['uae' => 1], '2026-11-12T09:00:00Z')
        );

        $this->assertSame(ApplyOutcome::STALE, $outcome->status);
        $this->assertTrue($this->engine()->read('users', 'usr_remote_1')->isDeleted());
    }
}
