<?php

namespace App\DStore\Storage;

use App\DStore\Support\CanonicalJson;
use App\DStore\Support\Timestamp;

/**
 * Events this node could not vouch for.
 *
 * An event reaches the quarantine when a peer refuses it for good — the
 * signature does not match, or the event is malformed. Retrying such an
 * event forever would block the replication stream behind it, so the node
 * sets it aside, keeps it for the operator to inspect, and says so in its
 * health state.
 *
 * The event stays in the committed log. Quarantining is a statement about
 * the event, not a deletion: nothing is thrown away and the repair command
 * can still sign the event properly afterwards.
 */
final class EventQuarantine
{
    public function __construct(
        private readonly StoragePaths $paths,
        private readonly HealthState $health,
    ) {}

    /**
     * Records the event as refused and flags the node as degraded.
     */
    public function quarantine(Event $event, string $reason, string $peerId = ''): void
    {
        $directory = $this->paths->quarantinedEvents();

        AtomicFile::ensureDirectory($directory);

        $name = $event->origin.'-'.sprintf('%08d', $event->seq);

        AtomicFile::write(
            $directory.'/'.$name.'.event',
            CanonicalJson::encode($event->toArray()),
        );

        AtomicFile::write(
            $directory.'/'.$name.'.reason',
            CanonicalJson::encode([
                'event_id' => $event->eventId,
                'origin' => $event->origin,
                'seq' => $event->seq,
                'reason' => $reason,
                'peer' => $peerId,
                'quarantined_at' => Timestamp::now(),
            ]),
        );

        $this->health->markCorrupted(
            $this->paths->event($event->origin, $event->seq),
            $peerId !== '' ? "event {$event->eventId} refused by {$peerId}: {$reason}" : "event {$event->eventId}: {$reason}",
        );
    }

    /**
     * @return array<int, array{event_id: string, origin: string, seq: int, reason: string, peer: string, quarantined_at: string}>
     */
    public function all(): array
    {
        $directory = $this->paths->quarantinedEvents();

        if (! is_dir($directory)) {
            return [];
        }

        $entries = [];

        foreach (scandir($directory) ?: [] as $file) {
            if (! str_ends_with($file, '.reason')) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($directory.'/'.$file), true);

            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        usort($entries, fn (array $a, array $b): int => [$a['origin'] ?? '', $a['seq'] ?? 0] <=> [$b['origin'] ?? '', $b['seq'] ?? 0]);

        return $entries;
    }

    public function has(string $eventId): bool
    {
        foreach ($this->all() as $entry) {
            if (($entry['event_id'] ?? '') === $eventId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Forgets an event that has been repaired.
     */
    public function forget(string $eventId): bool
    {
        $directory = $this->paths->quarantinedEvents();

        if (! is_dir($directory)) {
            return false;
        }

        $removed = false;

        foreach (scandir($directory) ?: [] as $file) {
            if (! str_ends_with($file, '.reason')) {
                continue;
            }

            $path = $directory.'/'.$file;
            $decoded = json_decode((string) file_get_contents($path), true);

            if (! is_array($decoded) || ($decoded['event_id'] ?? '') !== $eventId) {
                continue;
            }

            @unlink($path);
            @unlink(substr($path, 0, -strlen('.reason')).'.event');
            $removed = true;
        }

        return $removed;
    }
}
