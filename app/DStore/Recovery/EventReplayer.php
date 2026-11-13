<?php

namespace App\DStore\Recovery;

use App\DStore\Storage\EventLog;
use App\DStore\Synchronization\ApplyOutcome;
use App\DStore\Synchronization\EventApplier;

/**
 * Replays the committed event log through the normal apply path.
 *
 * This is the recovery equivalent of a node starting from an empty record
 * store: every event already passed signature verification when it entered
 * the log, and the applier still applies the version-vector rules, so a
 * newer local record can never be overwritten by an older event. Indexes are
 * rebuilt once, after the replay, instead of on every event.
 */
final class EventReplayer
{
    public function __construct(
        private readonly EventLog $events,
        private readonly EventApplier $applier,
        private readonly IndexRebuilder $indexes,
    ) {}

    /**
     * @return array{checked: int, applied: int, duplicate: int, stale: int, conflict: int, rejected: int, records: int}
     */
    public function replay(): array
    {
        $counts = [
            'checked' => 0,
            'applied' => 0,
            'duplicate' => 0,
            'stale' => 0,
            'conflict' => 0,
            'rejected' => 0,
        ];

        foreach ($this->events->all() as $event) {
            if ($event->eventId === '' || $event->collection === '' || $event->recordId === '') {
                continue;
            }

            $counts['checked']++;

            $outcome = $this->applier->apply($event);

            switch ($outcome->status) {
                case ApplyOutcome::APPLIED:
                    $counts['applied']++;
                    break;
                case ApplyOutcome::CONFLICT:
                    $counts['conflict']++;
                    break;
                case ApplyOutcome::DUPLICATE:
                    $counts['duplicate']++;
                    break;
                case ApplyOutcome::STALE:
                    $counts['stale']++;
                    break;
                default:
                    $counts['rejected']++;
                    break;
            }
        }

        $indexes = $this->indexes->rebuildAll();

        $counts['records'] = $indexes['records'];

        return $counts;
    }
}
