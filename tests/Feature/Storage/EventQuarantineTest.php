<?php

namespace Tests\Feature\Storage;

use App\DStore\Storage\Event;
use App\DStore\Storage\EventQuarantine;
use App\DStore\Storage\HealthState;
use App\DStore\Storage\RecordDocument;
use Tests\TestCase;

class EventQuarantineTest extends TestCase
{
    private function event(string $origin = 'uae', int $seq = 1): Event
    {
        $document = RecordDocument::make(
            'users',
            'usr_remote_1',
            ['name' => 'Remote'],
            ['uae' => 1],
            '2026-11-12T10:00:00Z',
        );

        return Event::compose(
            $origin,
            $seq,
            Event::TYPE_CREATED,
            $document,
            ['uae' => 1],
            '2026-11-12T10:00:00Z',
            null,
        );
    }

    public function test_a_refused_event_is_kept_for_inspection(): void
    {
        $event = $this->event();

        $this->app->make(EventQuarantine::class)->quarantine($event, 'invalid event signature', 'pakistan');

        $entries = $this->app->make(EventQuarantine::class)->all();

        $this->assertCount(1, $entries);
        $this->assertSame($event->eventId, $entries[0]['event_id']);
        $this->assertSame('invalid event signature', $entries[0]['reason']);
        $this->assertSame('pakistan', $entries[0]['peer']);
        $this->assertSame('uae', $entries[0]['origin']);
        $this->assertSame(1, $entries[0]['seq']);

        $stored = $this->app->make(EventQuarantine::class)->all();
        $this->assertCount(1, $stored);
    }

    public function test_the_quarantined_event_keeps_its_full_content(): void
    {
        $event = $this->event();

        $this->app->make(EventQuarantine::class)->quarantine($event, 'malformed event');

        $path = $this->storagePath('metadata/quarantine/events/uae-00000001.event');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertSame($event->eventId, $decoded['event_id']);
        $this->assertSame(['name' => 'Remote'], $decoded['payload']['data']);
    }

    public function test_quarantining_flags_the_node_as_degraded(): void
    {
        $event = $this->event();

        $this->app->make(EventQuarantine::class)->quarantine($event, 'invalid event signature', 'pakistan');

        $health = $this->app->make(HealthState::class);

        $this->assertTrue($health->isDegraded());
        $this->assertStringContainsString('invalid event signature', $health->issues()[0]['reason']);
    }

    public function test_a_quarantined_event_is_found_by_its_id(): void
    {
        $event = $this->event();

        $quarantine = $this->app->make(EventQuarantine::class);

        $this->assertFalse($quarantine->has($event->eventId));

        $quarantine->quarantine($event, 'malformed event');

        $this->assertTrue($quarantine->has($event->eventId));
    }

    public function test_a_repaired_event_can_be_forgotten(): void
    {
        $event = $this->event();

        $quarantine = $this->app->make(EventQuarantine::class);

        $quarantine->quarantine($event, 'malformed event');

        $this->assertTrue($quarantine->forget($event->eventId));
        $this->assertFalse($quarantine->has($event->eventId));
        $this->assertSame([], $quarantine->all());

        $this->assertFileDoesNotExist($this->storagePath('metadata/quarantine/events/uae-00000001.event'));
    }

    public function test_forgetting_an_event_that_was_never_quarantined_changes_nothing(): void
    {
        $quarantine = $this->app->make(EventQuarantine::class);

        $this->assertFalse($quarantine->forget('uae-00000001-deadbeef'));
    }
}
