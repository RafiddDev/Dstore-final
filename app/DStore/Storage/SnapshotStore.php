<?php

namespace App\DStore\Storage;

use App\DStore\Support\CanonicalJson;
use App\DStore\Support\Hash;
use App\DStore\Support\Timestamp;
use App\DStore\Support\VersionVector;

final class SnapshotStore
{
    public function __construct(
        private readonly StoragePaths $paths,
        private readonly RecordStore $records,
    ) {}

    public function create(string $collection, string $nodeId): string
    {
        $documents = [];
        $vector = [];

        foreach ($this->records->scan($collection) as $document) {
            if ($document->isDeleted()) {
                continue;
            }

            $documents[] = $document->toArray();
            $vector = VersionVector::merge($vector, $document->version());
        }

        $name = sprintf('%s-%s-%s.snap', $collection, gmdate('YmdHis'), bin2hex(random_bytes(3)));

        $payload = [
            'collection' => $collection,
            'node_id' => $nodeId,
            'created_at' => Timestamp::now(),
            'count' => count($documents),
            'vector' => $vector,
            'records' => $documents,
        ];

        $compressed = gzencode(CanonicalJson::encode($payload), 6);

        AtomicFile::write($this->paths->snapshots().'/'.$name, $compressed);

        $manifest = [
            'name' => $name,
            'collection' => $collection,
            'node_id' => $nodeId,
            'created_at' => $payload['created_at'],
            'count' => $payload['count'],
            'vector' => $vector,
            'sha256' => Hash::sha256($compressed),
            'size' => strlen($compressed),
        ];

        AtomicFile::write(
            $this->paths->snapshots().'/'.preg_replace('/\.snap$/', '.manifest', $name),
            CanonicalJson::encode($manifest),
        );

        return $name;
    }

    public function list(): array
    {
        $directory = $this->paths->snapshots();

        if (! is_dir($directory)) {
            return [];
        }

        $manifests = [];

        foreach (scandir($directory) ?: [] as $file) {
            if (! str_ends_with($file, '.manifest')) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($directory.'/'.$file), true);

            if (is_array($decoded)) {
                $manifests[] = $decoded;
            }
        }

        usort($manifests, fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $manifests;
    }

    public function read(string $name): ?array
    {
        $path = $this->paths->snapshots().'/'.basename($name);

        if (! is_file($path)) {
            return null;
        }

        $compressed = (string) file_get_contents($path);
        $decoded = json_decode((string) gzdecode($compressed), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function verify(string $name): bool
    {
        $snapshotPath = $this->paths->snapshots().'/'.basename($name);
        $manifestPath = $this->paths->snapshots().'/'.preg_replace('/\.snap$/', '.manifest', basename($name));

        if (! is_file($snapshotPath) || ! is_file($manifestPath)) {
            return false;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            return false;
        }

        $hash = Hash::sha256((string) file_get_contents($snapshotPath));

        return hash_equals((string) ($manifest['sha256'] ?? ''), $hash);
    }

    public function delete(string $name): void
    {
        $base = basename($name);

        foreach ([$base, preg_replace('/\.snap$/', '.manifest', $base)] as $file) {
            $path = $this->paths->snapshots().'/'.$file;

            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
