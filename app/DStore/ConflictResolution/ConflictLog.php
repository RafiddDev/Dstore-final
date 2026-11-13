<?php

namespace App\DStore\ConflictResolution;

use App\DStore\Storage\AtomicFile;
use App\DStore\Storage\Event;
use App\DStore\Storage\RecordDocument;
use App\DStore\Storage\StoragePaths;
use App\DStore\Support\CanonicalJson;
use App\DStore\Support\Timestamp;

/**
 * The conflicts this node has seen.
 *
 * Every concurrent write that had to be resolved leaves an entry here, so an
 * operator can see what was decided, by which policy and between which
 * versions — and so the dashboard can show real numbers instead of a guess.
 */
final class ConflictLog
{
    public function __construct(private readonly StoragePaths $paths) {}

    public function record(
        Event $event,
        RecordDocument $local,
        RecordDocument $incoming,
        ResolvedConflict $resolved,
    ): void {
        $entries = $this->all();

        $entries[] = [
            'event_id' => $event->eventId,
            'origin' => $event->origin,
            'collection' => $event->collection,
            'record_id' => $event->recordId,
            'reason' => $resolved->reason,
            'local_version' => $local->version(),
            'local_writer' => $local->writer(),
            'incoming_version' => $incoming->version(),
            'incoming_writer' => $incoming->writer(),
            'winner_version' => $resolved->winner->version(),
            'winner_writer' => $resolved->winner->writer(),
            'detected_at' => Timestamp::now(),
        ];

        $limit = (int) config('dstore.consistency.conflict_log_limit', 200);

        if ($limit > 0 && count($entries) > $limit) {
            $entries = array_slice($entries, -$limit);
        }

        AtomicFile::write($this->paths->conflictLog(), CanonicalJson::encode($entries));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $path = $this->paths->conflictLog();

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    public function count(): int
    {
        return count($this->all());
    }
}
