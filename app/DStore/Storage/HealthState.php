<?php

namespace App\DStore\Storage;

use App\DStore\Support\CanonicalJson;
use App\DStore\Support\Timestamp;

final class HealthState
{
    private array $issues = [];

    private bool $loaded = false;

    public function __construct(private readonly StoragePaths $paths) {}

    public function markCorrupted(string $path, string $reason): void
    {
        $this->load();

        $this->issues[] = [
            'path' => $path,
            'reason' => $reason,
            'detected_at' => Timestamp::now(),
        ];

        $this->persist();
    }

    public function isDegraded(): bool
    {
        $this->load();

        return $this->issues !== [];
    }

    public function issues(): array
    {
        $this->load();

        return $this->issues;
    }

    public function clear(): void
    {
        $this->load();

        $this->issues = [];

        $this->persist();
    }

    /**
     * Removes the issues that mention the given text.
     *
     * Used when the cause of an issue has been dealt with, so the node does
     * not stay degraded for a problem that no longer exists.
     */
    public function resolveMatching(string $needle): int
    {
        $this->load();

        $before = count($this->issues);

        $this->issues = array_values(array_filter(
            $this->issues,
            fn (array $issue): bool => ! str_contains((string) ($issue['reason'] ?? ''), $needle),
        ));

        $removed = $before - count($this->issues);

        if ($removed > 0) {
            $this->persist();
        }

        return $removed;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        $path = $this->paths->healthFile();

        if (! is_file($path)) {
            return;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (is_array($decoded)) {
            $this->issues = $decoded;
        }
    }

    private function persist(): void
    {
        AtomicFile::write($this->paths->healthFile(), CanonicalJson::encode($this->issues));
    }
}
