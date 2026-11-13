<?php

namespace Tests\Feature\Console;

use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\Event;
use App\DStore\Storage\EventLog;
use App\DStore\Storage\EventQuarantine;
use App\DStore\Storage\HealthState;
use App\DStore\Storage\RecordDocument;
use App\DStore\Storage\StoragePaths;
use App\DStore\Support\CanonicalJson;
use Tests\TestCase;

class EventRepairCommandTest extends TestCase
{
    private function paths(): StoragePaths
    {
        return $this->app->make(StoragePaths::class);
    }

    private function events(): EventLog
    {
        return $this->app->make(EventLog::class);
    }

    private function unsignedEvent(string $origin = 'test-node', int $seq = 1): Event
    {
        $document = RecordDocument::make(
            'users',
            'usr_x',
            ['name' => 'Unsigned'],
            [$origin => $seq],
            '2026-11-12T10:00:00Z',
        );

        return Event::compose(
            $origin,
            $seq,
            Event::TYPE_CREATED,
            $document,
            [$origin => $seq],
            '2026-11-12T10:00:00Z',
            null,
        );
    }

    private function writeEvent(Event $event): void
    {
        $directory = dirname($this->paths()->event($event->origin, $event->seq));

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents(
            $this->paths()->event($event->origin, $event->seq),
            CanonicalJson::encode($event->toArray()),
        );
    }

    public function test_an_unsigned_event_is_signed(): void
    {
        $event = $this->unsignedEvent();

        $this->writeEvent($event);

        $this->artisan('dstore:events:repair')
            ->expectsOutputToContain('Unsigned events:')
            ->expectsOutputToContain('Signed now:')
            ->assertSuccessful();

        $repaired = $this->events()->read('test-node', 1);

        $this->assertNotNull($repaired);
        $this->assertTrue($repaired->isSigned());
        $this->assertSame($event->eventId, $repaired->eventId);
    }

    public function test_a_dry_run_leaves_the_event_unsigned(): void
    {
        $event = $this->unsignedEvent();

        $this->writeEvent($event);

        $this->artisan('dstore:events:repair', ['--dry-run' => true])
            ->expectsOutputToContain('would sign')
            ->assertSuccessful();

        $repaired = $this->events()->read('test-node', 1);

        $this->assertNotNull($repaired);
        $this->assertFalse($repaired->isSigned());
    }

    public function test_an_event_whose_content_no_longer_matches_its_id_is_refused(): void
    {
        $event = $this->unsignedEvent();

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
        );

        $this->writeEvent($tampered);

        $this->artisan('dstore:events:repair')
            ->expectsOutputToContain('tampered')
            ->expectsOutputToContain('Tampered:')
            ->assertFailed();

        $stored = $this->events()->read('test-node', 1);

        $this->assertNotNull($stored);
        $this->assertFalse($stored->isSigned());
    }

    public function test_a_quarantined_event_is_released_once_it_is_signed(): void
    {
        $event = $this->unsignedEvent();

        $this->writeEvent($event);

        // The event is refused by a peer and set aside.
        $this->app->make(EventQuarantine::class)->quarantine($event, 'invalid event signature', 'uae');

        $this->artisan('dstore:events:repair')->assertSuccessful();

        $this->assertFalse($this->app->make(EventQuarantine::class)->has($event->eventId));
        $this->assertFalse($this->app->make(HealthState::class)->isDegraded());
    }

    public function test_a_repaired_event_is_queued_for_the_peers_again(): void
    {
        $event = $this->unsignedEvent();

        $this->writeEvent($event);

        $this->events()->enqueuePending('uae', $event);

        $this->artisan('dstore:events:repair')->assertSuccessful();

        $queued = $this->events()->pendingEvents('uae');

        $this->assertCount(1, $queued);
        $this->assertTrue($queued[0]->isSigned());
    }
}
