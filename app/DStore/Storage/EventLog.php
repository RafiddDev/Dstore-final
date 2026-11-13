<?php

namespace App\DStore\Storage;

use App\DStore\Storage\Exceptions\StorageException;
use App\DStore\Support\CanonicalJson;
use App\DStore\Support\Timestamp;

final class EventLog
{
    private ?array $appliedCache = null;

    public function __construct(private readonly StoragePaths $paths) {}

    public function nextSeq(string $origin): int
    {
        $path = $this->paths->counter($origin);
        AtomicFile::ensureDirectory(dirname($path));

        if (! is_file($path)) {
            AtomicFile::write($path, '0');
        }

        $handle = fopen($path, 'r+');

        if ($handle === false) {
            throw new StorageException("Unable to open sequence counter: {$path}");
        }

        try {
            flock($handle, LOCK_EX);

            $current = (int) trim((string) stream_get_contents($handle));
            $next = $current + 1;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $next);
            fflush($handle);
            @fsync($handle);

            flock($handle, LOCK_UN);

            return $next;
        } finally {
            fclose($handle);
        }
    }

    public function lastSeq(string $origin): int
    {
        $directory = $this->paths->events($origin);

        if (! is_dir($directory)) {
            return 0;
        }

        $max = 0;

        foreach (scandir($directory) ?: [] as $file) {
            if (preg_match('/^(\d{8})\.event$/', $file, $matches)) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return $max;
    }

    public function append(Event $event): void
    {
        AtomicFile::write(
            $this->paths->event($event->origin, $event->seq),
            CanonicalJson::encode($event->toArray()),
        );
    }

    public function read(string $origin, int $seq): ?Event
    {
        $path = $this->paths->event($origin, $seq);

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? Event::fromArray($decoded) : null;
    }

    /**
     * Where the event of an origin and sequence is stored on disk.
     */
    public function pathFor(string $origin, int $seq): string
    {
        return $this->paths->event($origin, $seq);
    }

    public function lastEventId(string $origin): ?string
    {
        $seq = $this->lastSeq($origin);

        if ($seq === 0) {
            return null;
        }

        return $this->read($origin, $seq)?->eventId;
    }

    public function all(?string $origin = null): iterable
    {
        $origins = $origin !== null ? [$origin] : $this->origins();

        foreach ($origins as $node) {
            $directory = $this->paths->events($node);

            if (! is_dir($directory)) {
                continue;
            }

            $files = [];

            foreach (scandir($directory) ?: [] as $file) {
                if (str_ends_with($file, '.event')) {
                    $files[] = $file;
                }
            }

            sort($files);

            foreach ($files as $file) {
                $decoded = json_decode((string) file_get_contents($directory.'/'.$file), true);

                if (is_array($decoded)) {
                    yield Event::fromArray($decoded);
                }
            }
        }
    }

    public function markApplied(string $eventId): void
    {
        $this->loadAppliedCache();

        if (isset($this->appliedCache[$eventId])) {
            return;
        }

        AtomicFile::append($this->paths->appliedIndex(), $eventId."\n");

        $this->appliedCache[$eventId] = true;
    }

    public function hasApplied(string $eventId): bool
    {
        $this->loadAppliedCache();

        return isset($this->appliedCache[$eventId]);
    }

    public function appliedCount(): int
    {
        $this->loadAppliedCache();

        return count($this->appliedCache);
    }

    /**
     * The highest **contiguous** sequence this node has applied per origin.
     *
     * Contiguity matters: if event 6 arrived before event 5, reporting 6
     * would tell the peers that 5 is not needed any more and the hole would
     * never be filled. Reporting the prefix instead makes the peer send the
     * missing range again.
     *
     * @return array<string, int>
     */
    public function appliedVector(): array
    {
        $this->loadAppliedCache();

        $byOrigin = [];

        foreach (array_keys($this->appliedCache) as $eventId) {
            if (preg_match('/^(.+)-(\d{8})-[0-9a-f]{8}$/', (string) $eventId, $matches)) {
                $origin = $matches[1];
                $byOrigin[$origin][(int) $matches[2]] = true;
            }
        }

        $vector = [];

        foreach ($byOrigin as $origin => $sequences) {
            $seq = 0;

            while (isset($sequences[$seq + 1])) {
                $seq++;
            }

            $vector[$origin] = $seq;
        }

        ksort($vector);

        return $vector;
    }

    public function enqueuePending(string $peer, Event $event): void
    {
        AtomicFile::write(
            $this->paths->pendingEvent($peer, $event->origin, $event->seq),
            CanonicalJson::encode($event->toArray()),
        );
    }

    /**
     * Rewrites the copy of an event that is queued for a peer.
     *
     * The queue holds a full copy of the event, so a change to the event —
     * a signature added by the repair command, for instance — has to be
     * copied into the queue as well.
     */
    public function refreshPending(string $peer, Event $event): void
    {
        $path = $this->paths->pendingEvent($peer, $event->origin, $event->seq);

        if (! is_file($path)) {
            return;
        }

        AtomicFile::write($path, CanonicalJson::encode($event->toArray()));
    }

    public function pendingEvents(string $peer): array
    {
        $directory = $this->paths->pending($peer);

        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (scandir($directory) ?: [] as $file) {
            if (str_ends_with($file, '.event')) {
                $files[] = $file;
            }
        }

        sort($files);

        $events = [];

        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($directory.'/'.$file), true);

            if (is_array($decoded)) {
                $events[] = Event::fromArray($decoded);
            }
        }

        return $events;
    }

    public function pendingCount(string $peer): int
    {
        return count($this->pendingEvents($peer));
    }

    /**
     * Every peer this node has ever queued an event for or tracked a
     * checkpoint with.
     *
     * @return string[]
     */
    public function pendingPeers(): array
    {
        $peers = [];

        foreach ([$this->paths->root().'/events/pending', $this->paths->root().'/events/sync'] as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                if (is_dir($directory.'/'.$entry)) {
                    $peers[] = $entry;
                } elseif (str_ends_with($entry, '.json')) {
                    $peers[] = substr($entry, 0, -5);
                }
            }
        }

        $peers = array_values(array_unique($peers));
        sort($peers);

        return $peers;
    }

    public function markDelivered(string $peer, string $origin, int $seq): void
    {
        $this->dropPending($peer, $origin, $seq);
    }

    /**
     * Removes an event from the queue for a peer without claiming it was
     * delivered. Used when the event can never be delivered.
     */
    public function dropPending(string $peer, string $origin, int $seq): void
    {
        $path = $this->paths->pendingEvent($peer, $origin, $seq);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function cursor(string $peer): array
    {
        $path = $this->paths->syncCursor($peer);

        if (! is_file($path)) {
            return [
                'peer' => $peer,
                'applied' => [],
                'delivered' => [],
                'pending_count' => 0,
                'last_heartbeat_at' => null,
                'last_sync_at' => null,
                'state' => 'unknown',
            ];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function saveCursor(string $peer, array $cursor): void
    {
        $cursor['peer'] = $peer;
        $cursor['updated_at'] = Timestamp::now();

        AtomicFile::write(
            $this->paths->syncCursor($peer),
            CanonicalJson::encode($cursor),
        );
    }

    private function loadAppliedCache(): void
    {
        if ($this->appliedCache !== null) {
            return;
        }

        $this->appliedCache = [];

        $path = $this->paths->appliedIndex();

        if (! is_file($path)) {
            return;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            flock($handle, LOCK_SH);

            while (($line = fgets($handle)) !== false) {
                $eventId = trim($line);

                if ($eventId !== '') {
                    $this->appliedCache[$eventId] = true;
                }
            }

            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function origins(): array
    {
        $directory = $this->paths->root().'/events/committed';

        if (! is_dir($directory)) {
            return [];
        }

        $origins = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (is_dir($directory.'/'.$entry)) {
                $origins[] = $entry;
            }
        }

        sort($origins);

        return $origins;
    }
}
