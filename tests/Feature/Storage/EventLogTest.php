<?php

namespace Tests\Feature\Storage;

use App\DStore\Storage\Event;
use App\DStore\Storage\EventLog;
use App\DStore\Storage\RecordDocument;
use App\DStore\Support\Timestamp;
use Tests\TestCase;

class EventLogTest extends TestCase
{
    private function event(int $seq = 1, string $recordId = 'usr_test1'): Event
    {
        $document = RecordDocument::make('users', $recordId, ['name' => 'Ada'], ['test-node' => $seq], Timestamp::now());

        return Event::compose('test-node', $seq, Event::TYPE_CREATED, $document, ['test-node' => $seq], Timestamp::now(), null);
    }

    public function test_sequence_numbers_are_monotonic(): void
    {
        $events = $this->app->make(EventLog::class);

        $this->assertSame(1, $events->nextSeq('test-node'));
        $this->assertSame(2, $events->nextSeq('test-node'));
        $this->assertSame(3, $events->nextSeq('test-node'));
    }

    public function test_events_are_appended_read_back_and_chained(): void
    {
        $events = $this->app->make(EventLog::class);

        $first = $events->nextSeq('test-node');
        $eventOne = Event::compose(
            'test-node',
            $first,
            Event::TYPE_CREATED,
            RecordDocument::make('users', 'usr_a', ['name' => 'A'], ['test-node' => 1], Timestamp::now()),
            ['test-node' => 1],
            Timestamp::now(),
            null,
        );
        $events->append($eventOne);

        $second = $events->nextSeq('test-node');
        $eventTwo = Event::compose(
            'test-node',
            $second,
            Event::TYPE_UPDATED,
            RecordDocument::make('users', 'usr_a', ['name' => 'B'], ['test-node' => 2], Timestamp::now()),
            ['test-node' => 2],
            Timestamp::now(),
            $events->lastEventId('test-node'),
        );
        $events->append($eventTwo);

        $this->assertSame(2, $events->lastSeq('test-node'));
        $this->assertSame($eventOne->eventId, $eventTwo->prevEventId);
        $this->assertSame($eventOne->eventId, $events->read('test-node', $first)?->eventId);
        $this->assertCount(2, iterator_to_array($events->all()));
    }

    public function test_applied_events_are_deduplicated(): void
    {
        $events = $this->app->make(EventLog::class);
        $event = $this->event();

        $this->assertFalse($events->hasApplied($event->eventId));

        $events->markApplied($event->eventId);

        $this->assertTrue($events->hasApplied($event->eventId));
        $this->assertSame(1, $events->appliedCount());
    }

    public function test_pending_queue_tracks_undelivered_events_per_peer(): void
    {
        $events = $this->app->make(EventLog::class);
        $event = $this->event();

        $events->enqueuePending('uae', $event);

        $this->assertSame(1, $events->pendingCount('uae'));
        $this->assertSame(0, $events->pendingCount('uk'));
        $this->assertSame($event->eventId, $events->pendingEvents('uae')[0]->eventId);

        $events->markDelivered('uae', $event->origin, $event->seq);

        $this->assertSame(0, $events->pendingCount('uae'));
    }

    public function test_sync_cursors_are_persisted_per_peer(): void
    {
        $events = $this->app->make(EventLog::class);

        $events->saveCursor('uae', [
            'applied' => ['test-node' => 7],
            'delivered' => ['test-node' => 5],
            'state' => 'syncing',
        ]);

        $cursor = $events->cursor('uae');

        $this->assertSame('uae', $cursor['peer']);
        $this->assertSame(['test-node' => 7], $cursor['applied']);
        $this->assertSame('syncing', $cursor['state']);
        $this->assertArrayHasKey('updated_at', $cursor);
    }

    public function test_unknown_peer_cursor_returns_the_default_shape(): void
    {
        $cursor = $this->app->make(EventLog::class)->cursor('unknown');

        $this->assertSame('unknown', $cursor['peer']);
        $this->assertSame([], $cursor['applied']);
        $this->assertSame('unknown', $cursor['state']);
    }
}
