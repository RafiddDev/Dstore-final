<?php

namespace App\DStore\Security;

use App\DStore\Storage\AtomicFile;
use App\DStore\Storage\StoragePaths;
use App\DStore\Support\CanonicalJson;

/**
 * Replay protection.
 *
 * A message is accepted only if its identifier has never been seen and its
 * timestamp falls inside the accepted window. The window also bounds how long
 * an identifier has to be remembered, so the state file cannot grow forever.
 */
final class ReplayGuard
{
    public function __construct(
        private readonly StoragePaths $paths,
        private readonly int $window = 300,
        private readonly int $maxClockSkew = 300,
    ) {}

    public function accept(string $messageId, int $timestamp): bool
    {
        if ($messageId === '') {
            return false;
        }

        $now = time();

        if ($timestamp < ($now - $this->window)) {
            return false;
        }

        if ($timestamp > ($now + $this->maxClockSkew)) {
            return false;
        }

        $entries = $this->read();

        if (isset($entries[$messageId])) {
            return false;
        }

        $entries[$messageId] = $timestamp;

        $entries = $this->prune($entries, $now);

        $this->write($entries);

        return true;
    }

    public function seen(string $messageId): bool
    {
        return isset($this->read()[$messageId]);
    }

    public function count(): int
    {
        return count($this->read());
    }

    public function clear(): void
    {
        $this->write([]);
    }

    private function prune(array $entries, int $now): array
    {
        foreach ($entries as $messageId => $timestamp) {
            if ($timestamp < ($now - $this->window)) {
                unset($entries[$messageId]);
            }
        }

        return $entries;
    }

    private function read(): array
    {
        $path = $this->paths->replayFile();

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function write(array $entries): void
    {
        AtomicFile::write($this->paths->replayFile(), CanonicalJson::encode($entries));
    }
}
