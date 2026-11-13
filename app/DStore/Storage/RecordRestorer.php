<?php

namespace App\DStore\Storage;

use App\DStore\Support\CanonicalJson;
use App\DStore\Support\VersionVector;

/**
 * Restores the records that the event log knows about but the record store
 * has lost.
 *
 * The event log is the authority: it holds the full state of every record
 * at the moment it was written, so a record that went missing from the store
 * can always be rebuilt from it.
 *
 * A record that is present is never touched. The log can be behind a record
 * that was written after the last event, and overwriting it with older data
 * would be exactly the kind of loss this system is built to avoid.
 */
final class RecordRestorer
{
    public function __construct(
        private readonly RecordStore $records,
        private readonly IndexStore $index,
        private readonly EventLog $events,
        private readonly TombstoneStore $tombstones,
    ) {}

    /**
     * @return array{checked: int, restored: int, missing: list<string>}
     */
    public function restore(): array
    {
        $latest = $this->latestEvents();

        $restored = 0;
        $missing = [];

        foreach ($latest as $event) {
            if ($this->records->exists($event->collection, $event->recordId)) {
                continue;
            }

            $missing[] = $event->recordId;

            $this->write($event);

            $restored++;
        }

        return [
            'checked' => count($latest),
            'restored' => $restored,
            'missing' => $missing,
        ];
    }

    /**
     * Makes the stored records agree with what the event log proves.
     *
     * Two things can drift between the log and the store:
     *
     *  - the writer, on records written before the writer was tracked;
     *  - the history, on records whose event was applied by an older node
     *    that recorded a state the event already carried.
     *
     * Both are metadata. The data, the version and the tombstone state are
     * never modified, and a record is only rewritten when the event
     * describes exactly the state the record is in.
     *
     * @return array{checked: int, updated: int, records: list<string>}
     */
    public function reconcile(bool $dryRun = false): array
    {
        $latest = $this->latestEvents();
        $updated = [];

        foreach ($latest as $event) {
            $document = $this->records->read($event->collection, $event->recordId);

            if ($document === null) {
                continue;
            }

            if (CanonicalJson::encode($document->version()) !== CanonicalJson::encode($event->originClock)) {
                continue;
            }

            $reconciled = $this->reconcileDocument($document, $event);

            if ($reconciled === null) {
                continue;
            }

            if (! $dryRun) {
                $stored = $this->records->write($reconciled);

                $this->index->put(
                    $stored->collection,
                    $stored->recordId,
                    $this->records->indexEntry($stored),
                );
            }

            $updated[] = $event->recordId;
        }

        return [
            'checked' => count($latest),
            'updated' => count($updated),
            'records' => $updated,
        ];
    }

    /**
     * The document the log proves, or null when the record already agrees.
     */
    private function reconcileDocument(RecordDocument $document, Event $event): ?RecordDocument
    {
        $reconciled = $document;

        $writer = (string) ($document->meta['writer'] ?? '');

        if ($writer !== $event->origin) {
            $reconciled = $reconciled->withWriter($event->origin);
        }

        $history = $this->reconciledHistory($document->history(), $event);

        if ($history !== null) {
            $reconciled = $reconciled->withHistory($history);
        }

        if (CanonicalJson::encode($reconciled->meta) === CanonicalJson::encode($document->meta)) {
            return null;
        }

        return $reconciled;
    }

    /**
     * The history the event proves, or null when the local history holds
     * something the event does not know about.
     *
     * A history entry is dropped only when the event already records the
     * same version, so a state that only exists locally is always kept.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function reconciledHistory(array $history, Event $event): ?array
    {
        $expected = (array) ($event->payload['meta']['history'] ?? []);

        $known = [];

        foreach ($expected as $entry) {
            $known[CanonicalJson::encode((array) ($entry['version'] ?? []))] = true;
        }

        foreach ($history as $entry) {
            if (! isset($known[CanonicalJson::encode((array) ($entry['version'] ?? []))])) {
                return null;
            }
        }

        if (CanonicalJson::encode($history) === CanonicalJson::encode($expected)) {
            return null;
        }

        return $expected;
    }

    /**
     * The records the log knows about that are not in the store.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $missing = [];

        foreach ($this->latestEvents() as $event) {
            if (! $this->records->exists($event->collection, $event->recordId)) {
                $missing[] = $event->recordId;
            }
        }

        return $missing;
    }

    /**
     * The newest event for every record in the log.
     *
     * "Newest" is decided by the version vector, never by a timestamp.
     *
     * @return array<string, Event>
     */
    private function latestEvents(): array
    {
        $latest = [];

        foreach ($this->events->all() as $event) {
            if ($event->recordId === '' || $event->collection === '') {
                continue;
            }

            $current = $latest[$event->recordId] ?? null;

            if ($current === null || VersionVector::dominates($event->originClock, $current->originClock)) {
                $latest[$event->recordId] = $event;
            }
        }

        return $latest;
    }

    private function write(Event $event): void
    {
        $document = RecordDocument::fromEvent($event);

        $stored = $this->records->write($document);

        $this->index->put(
            $stored->collection,
            $stored->recordId,
            $this->records->indexEntry($stored),
        );

        if ($stored->isDeleted()) {
            $this->tombstones->write($stored, $stored->version(), (string) ($stored->meta['writer'] ?? $event->origin));
        }
    }
}
