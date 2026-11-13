<?php

namespace App\DStore\Storage;

use App\DStore\Support\CanonicalJson;
use App\DStore\Support\Hash;

final class IntegrityGuard
{
    public function __construct(
        private readonly StoragePaths $paths,
        private readonly HealthState $health,
    ) {}

    public function documentHash(array $meta, array $data): string
    {
        unset($meta['hash']);

        return Hash::sha256(CanonicalJson::encode(['meta' => $meta, 'data' => $data]));
    }

    public function verifyDocument(array $document): bool
    {
        $meta = (array) ($document['meta'] ?? []);
        $expected = (string) ($meta['hash'] ?? '');

        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, $this->documentHash($meta, (array) ($document['data'] ?? [])));
    }

    public function quarantine(string $path, string $reason): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        AtomicFile::ensureDirectory($this->paths->quarantine());

        $target = $this->paths->quarantine().'/'.basename($path).'.'.bin2hex(random_bytes(4));

        if (! rename($path, $target)) {
            return null;
        }

        $this->health->markCorrupted($path, $reason);

        return $target;
    }

    public function verifyTree(): array
    {
        $checked = 0;
        $corrupted = [];

        foreach ($this->collections() as $collection) {
            $directory = $this->paths->records($collection);

            if (! is_dir($directory)) {
                continue;
            }

            foreach ($this->recordFiles($directory) as $file) {
                $checked++;
                $reason = $this->verifyRecordFile($file);

                if ($reason !== null) {
                    $corrupted[] = ['path' => $file, 'reason' => $reason];
                }
            }
        }

        return [
            'checked' => $checked,
            'ok' => $checked - count($corrupted),
            'corrupted' => $corrupted,
            'degraded' => $this->health->isDegraded(),
        ];
    }

    public function collections(): array
    {
        $directory = $this->paths->root().'/records';

        if (! is_dir($directory)) {
            return [];
        }

        $collections = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (is_dir($directory.'/'.$entry)) {
                $collections[] = $entry;
            }
        }

        sort($collections);

        return $collections;
    }

    private function verifyRecordFile(string $file): ?string
    {
        $raw = @file_get_contents($file);

        if ($raw === false) {
            return 'unreadable file';
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            $this->quarantine($file, 'invalid json document');

            return 'invalid json document';
        }

        if (! $this->verifyDocument($decoded)) {
            $this->quarantine($file, 'hash mismatch');

            return 'hash mismatch';
        }

        return null;
    }

    private function recordFiles(string $directory): array
    {
        $files = [];

        foreach (scandir($directory) ?: [] as $shard) {
            if ($shard === '.' || $shard === '..') {
                continue;
            }

            $shardPath = $directory.'/'.$shard;

            if (! is_dir($shardPath)) {
                continue;
            }

            foreach (scandir($shardPath) ?: [] as $file) {
                if (str_ends_with($file, '.json')) {
                    $files[] = $shardPath.'/'.$file;
                }
            }
        }

        return $files;
    }
}
