<?php

namespace App\DStore\Synchronization;

use App\DStore\ConflictResolution\ConflictLog;
use App\DStore\ConflictResolution\ConflictResolver;
use App\DStore\ConflictResolution\ResolvedConflict;
use App\DStore\Security\EventVerifier;
use App\DStore\Storage\Event;
use App\DStore\Storage\EventLog;
use App\DStore\Storage\FileLocker;
use App\DStore\Storage\IndexStore;
use App\DStore\Storage\RecordDocument;
use App\DStore\Storage\RecordStore;
use App\DStore\Storage\TombstoneStore;
use App\DStore\Support\CanonicalJson;
use App\DStore\Support\VersionVector;

/**
 * Applies a verified event to the local records.
 *
 * The order of business is fixed by the version vector, never by a clock:
 *
 *  - the incoming version dominates the local one → the incoming write wins;
 *  - the local version dominates the incoming one → the incoming write is
 *    stale, the local data stays, the event is still marked applied so it is
 *    not offered again;
 *  - neither dominates → the two writes are concurrent and the conflict
 *    resolver decides, deterministically and identically on every node.
 */
final class EventApplier
{
    public function __construct(
        private readonly RecordStore $records,
        private readonly EventLog $events,
        private readonly IndexStore $index,
        private readonly TombstoneStore $tombstones,
        private readonly EventVerifier $verifier,
        private readonly ConflictResolver $resolver,
        private readonly FileLocker $locker,
        private readonly ConflictLog $conflicts,
    ) {}

    public function apply(Event $event): ApplyOutcome
    {
        if ($event->eventId === '') {
            return ApplyOutcome::rejected('', '', 'event has no id');
        }

        if ($this->events->hasApplied($event->eventId)) {
            return ApplyOutcome::duplicate($event->eventId, $event->recordId);
        }

        if (! $this->verifier->isTrustedOrigin($event->origin)) {
            return ApplyOutcome::rejected(
                $event->eventId,
                $event->recordId,
                'origin node is not a trusted peer'
            );
        }

        if (! $this->verifier->verify($event)) {
            return ApplyOutcome::rejected(
                $event->eventId,
                $event->recordId,
                'invalid event signature'
            );
        }

        return $this->locker->withExclusiveLock(
            'record:'.$event->collection.':'.$event->recordId,
            fn (): ApplyOutcome => $this->applyLocked($event)
        );
    }

    private function applyLocked(Event $event): ApplyOutcome
    {
        $local = $this->records->read($event->collection, $event->recordId);
        $incoming = $this->documentFromEvent($event);

        if ($local === null) {
            $this->store($incoming, $incoming->version(), $event->origin, []);
            $this->events->markApplied($event->eventId);

            return ApplyOutcome::applied($event->eventId, $event->recordId, 'record created');
        }

        $localVersion = $local->version();
        $incomingVersion = $incoming->version();

        if (VersionVector::equals($localVersion, $incomingVersion)) {
            $this->events->markApplied($event->eventId);

            return ApplyOutcome::duplicate($event->eventId, $event->recordId);
        }

        if (VersionVector::dominates($incomingVersion, $localVersion)) {
            $this->store($incoming, $incomingVersion, $event->origin, $this->superseded($local));
            $this->events->markApplied($event->eventId);

            return ApplyOutcome::applied($event->eventId, $event->recordId, 'incoming version is newer');
        }

        if (VersionVector::dominates($localVersion, $incomingVersion)) {
            $this->events->markApplied($event->eventId);

            return ApplyOutcome::stale($event->eventId, $event->recordId);
        }

        $resolved = $this->resolver->resolve($local, $incoming);

        // The merged version dominates both sides, so any later event wins
        // against this one and the node cannot oscillate.
        $merged = VersionVector::merge($localVersion, $incomingVersion);

        $this->store(
            $resolved->winner,
            $merged,
            $this->writerFor($resolved, $local, $incoming, $event),
            $this->supersededStates($resolved),
        );

        $this->conflicts->record($event, $local, $incoming, $resolved);

        $this->events->markApplied($event->eventId);

        return ApplyOutcome::conflict($event->eventId, $event->recordId, $resolved->reason);
    }

    /**
     * The node that wrote the state the record is left in.
     *
     * A policy that merges the two sides produces a document that is neither
     * of them, so it carries its own writer.
     */
    private function writerFor(
        ResolvedConflict $resolved,
        RecordDocument $local,
        RecordDocument $incoming,
        Event $event,
    ): string {
        if ($resolved->winner === $incoming) {
            return $event->origin;
        }

        if ($resolved->winner === $local) {
            return $local->writer();
        }

        return $resolved->winner->writer();
    }

    /**
     * Rebuilds the document the event describes.
     *
     * Every event carries the full state of the record at the moment it was
     * produced, so applying an event never needs the record to exist first.
     */
    private function documentFromEvent(Event $event): RecordDocument
    {
        return RecordDocument::fromEvent($event);
    }

    /**
     * The history entry of the state that was just replaced.
     */
    private function superseded(RecordDocument $document): array
    {
        return [[
            'version' => $document->version(),
            'updated_at' => $document->updatedAt(),
            'deleted' => $document->isDeleted(),
            'writer' => $document->writer(),
        ]];
    }

    /**
     * The history entries of every state the resolution replaced.
     *
     * A policy that merges the two sides replaces both of them, so both are
     * recorded; nothing that was written is ever dropped silently.
     *
     * The entries are ordered by their version, never by the order the sides
     * happened to arrive in: the two nodes that hold the same pair of writes
     * must end up with the same history.
     *
     * @return list<array<string, mixed>>
     */
    private function supersededStates(ResolvedConflict $conflict): array
    {
        $documents = $conflict->superseded !== [] ? $conflict->superseded : [$conflict->loser];

        $states = [];

        foreach ($documents as $document) {
            $states[] = [
                'version' => $document->version(),
                'updated_at' => $document->updatedAt(),
                'deleted' => $document->isDeleted(),
                'writer' => $document->writer(),
            ];
        }

        usort(
            $states,
            fn (array $a, array $b): int => strcmp(
                CanonicalJson::encode($a['version']),
                CanonicalJson::encode($b['version']),
            ),
        );

        return $states;
    }

    private function store(RecordDocument $document, array $version, string $writer, array $history): void
    {
        $meta = $document->meta;
        $meta['version'] = $version;
        $meta['writer'] = $writer !== '' ? $writer : $document->writer();

        if ($history !== []) {
            $meta['history'] = RecordDocument::mergeHistory((array) ($meta['history'] ?? []), $history);
        }

        $prepared = new RecordDocument($document->recordId, $document->collection, $meta, $document->data);

        $stored = $this->records->write($prepared);

        $this->index->put(
            $stored->collection,
            $stored->recordId,
            $this->records->indexEntry($stored),
        );

        if ($stored->isDeleted()) {
            $this->tombstones->write($stored, $version, $meta['writer']);
        }
    }
}
