<?php

namespace App\DStore\Storage;

use App\DStore\Support\CanonicalJson;

/**
 * The durable inbox for events received from peers.
 *
 * An event is accepted here only after its signature has been verified
 * against the sending node's pinned key. Applying it to the local records is
 * the next stage, so a peer never has to trust a single request to be
 * correct: the event is on disk before anything is acknowledged.
 */
final class EventInbox
{
    public function __construct(
        private readonly StoragePaths $paths,
        private readonly EventLog $events,
    ) {}

    public function has(string $eventId): bool
    {
        if ($this->events->hasApplied($eventId)) {
            return true;
        }

        return isset($this->index()[$eventId]);
    }

    /**
     * Stores the event if it is not known yet.
     *
     * @return bool false when the event was already known
     */
    public function store(Event $event): bool
    {
        if ($this->has($event->eventId)) {
            return false;
        }

        AtomicFile::write(
            $this->paths->inboxEvent($event->origin, $event->seq),
            CanonicalJson::encode($event->toArray()),
        );

        $index = $this->index();
        $index[$event->eventId] = $event->origin.'|'.$event->seq;
        ksort($index);

        AtomicFile::write($this->paths->inboxIndex(), CanonicalJson::encode($index));

        return true;
    }

    /**
     * @return Event[] the events still waiting to be applied, oldest first
     */
    public function pending(): array
    {
        $events = [];

        foreach ($this->index() as $eventId => $location) {
            [$origin, $seq] = array_pad(explode('|', (string) $location, 2), 2, '0');

            $event = $this->read((string) $origin, (int) $seq);

            if ($event !== null && $event->eventId === $eventId) {
                $events[] = $event;
            }
        }

        usort($events, fn (Event $a, Event $b): int => [$a->origin, $a->seq] <=> [$b->origin, $b->seq]);

        return $events;
    }

    public function count(): int
    {
        return count($this->index());
    }

    /**
     * Marks an event as applied and clears it from the inbox.
     */
    public function markApplied(Event $event): void
    {
        $this->events->markApplied($event->eventId);

        $path = $this->paths->inboxEvent($event->origin, $event->seq);

        if (is_file($path)) {
            @unlink($path);
        }

        $index = $this->index();
        unset($index[$event->eventId]);
        ksort($index);

        AtomicFile::write($this->paths->inboxIndex(), CanonicalJson::encode($index));
    }

    private function index(): array
    {
        $path = $this->paths->inboxIndex();

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Reads an event back from the inbox, not from the committed log.
     */
    private function read(string $origin, int $seq): ?Event
    {
        $path = $this->paths->inboxEvent($origin, $seq);

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? Event::fromArray($decoded) : null;
    }
}
