<?php

namespace App\DStore\Recovery;

use App\DStore\Storage\AtomicFile;
use App\DStore\Storage\StoragePaths;
use App\DStore\Support\CanonicalJson;
use App\DStore\Support\Hash;
use App\DStore\Support\Timestamp;

/**
 * Full-tree backups with a hash manifest.
 *
 * A backup is a plain copy of the storage root under `backups/<name>/tree`
 * plus `manifest.json` that lists every relative path, its size and its
 * sha-256. Restoring verifies the hashes first, so a corrupted backup can
 * never silently replace good data.
 *
 * The `backups` directory itself is excluded from the copy, otherwise every
 * backup would contain all previous backups.
 */
final class BackupManager
{
    public function __construct(private readonly StoragePaths $paths) {}

    /**
     * @return array{name: string, directory: string, files: int, bytes: int, created_at: string}
     */
    public function create(?string $name = null): array
    {
        $name = $this->normalizeName($name ?? gmdate('Ymd-His').'-'.bin2hex(random_bytes(2)));
        $directory = $this->paths->backupDirectory($name);

        if (is_dir($directory)) {
            throw new \RuntimeException("Backup {$name} already exists.");
        }

        $tree = $this->paths->backupTree($name);
        AtomicFile::ensureDirectory($tree);

        $files = [];
        $bytes = 0;

        foreach ($this->walk() as $absolute => $relative) {
            $contents = @file_get_contents($absolute);

            if ($contents === false) {
                continue;
            }

            AtomicFile::write($tree.'/'.$relative, $contents);

            $size = strlen($contents);
            $bytes += $size;

            $files[] = [
                'path' => $relative,
                'sha256' => Hash::sha256($contents),
                'size' => $size,
            ];
        }

        usort($files, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        $manifest = [
            'name' => $name,
            'created_at' => Timestamp::now(),
            'count' => count($files),
            'bytes' => $bytes,
            'files' => $files,
        ];

        AtomicFile::write($this->paths->backupManifest($name), CanonicalJson::encode($manifest));

        return [
            'name' => $name,
            'directory' => $directory,
            'files' => count($files),
            'bytes' => $bytes,
            'created_at' => $manifest['created_at'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $directory = $this->paths->backups();

        if (! is_dir($directory)) {
            return [];
        }

        $backups = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_dir($directory.'/'.$entry)) {
                continue;
            }

            $manifest = $this->manifest($entry);

            if ($manifest !== null) {
                $backups[] = $manifest;
            }
        }

        usort($backups, fn (array $a, array $b): int => strcmp(
            (string) ($b['created_at'] ?? ''),
            (string) ($a['created_at'] ?? ''),
        ));

        return $backups;
    }

    /**
     * @return array{ok: bool, checked: int, bytes: int, mismatches: list<string>, missing: list<string>, unexpected: list<string>}
     */
    public function verify(string $name): array
    {
        $manifest = $this->manifest($name);

        if ($manifest === null) {
            throw new \RuntimeException("Backup {$name} has no manifest.");
        }

        $tree = $this->paths->backupTree($name);
        $checked = 0;
        $bytes = 0;
        $mismatches = [];
        $missing = [];
        $known = [];

        foreach ((array) ($manifest['files'] ?? []) as $file) {
            $relative = (string) ($file['path'] ?? '');

            if ($relative === '') {
                continue;
            }

            $known[$this->normalizeRelative($relative)] = true;
            $checked++;

            $path = $tree.'/'.$this->normalizeRelative($relative);

            if (! is_file($path)) {
                $missing[] = $relative;

                continue;
            }

            $contents = (string) file_get_contents($path);
            $bytes += strlen($contents);

            if (! hash_equals((string) ($file['sha256'] ?? ''), Hash::sha256($contents))) {
                $mismatches[] = $relative;
            }
        }

        $unexpected = [];

        foreach ($this->walkTree($tree) as $relative) {
            if (! isset($known[$relative])) {
                $unexpected[] = $relative;
            }
        }

        return [
            'ok' => $mismatches === [] && $missing === [] && $unexpected === [],
            'checked' => $checked,
            'bytes' => $bytes,
            'mismatches' => $mismatches,
            'missing' => $missing,
            'unexpected' => $unexpected,
        ];
    }

    /**
     * @return array{name: string, restored: int, skipped: int}
     */
    public function restore(string $name, bool $verify = true): array
    {
        $name = $this->normalizeName($name);

        if ($verify) {
            $result = $this->verify($name);

            if (! $result['ok']) {
                throw new \RuntimeException(
                    'Backup failed verification: '.implode(', ', array_merge(
                        $result['mismatches'],
                        $result['missing'],
                        $result['unexpected'],
                    ))
                );
            }
        }

        $manifest = $this->manifest($name);

        if ($manifest === null) {
            throw new \RuntimeException("Backup {$name} has no manifest.");
        }

        $tree = $this->paths->backupTree($name);
        $root = $this->paths->root();
        $restored = 0;
        $skipped = 0;

        foreach ((array) ($manifest['files'] ?? []) as $file) {
            $relative = $this->normalizeRelative((string) ($file['path'] ?? ''));

            if ($relative === '' || $this->isExcluded($relative)) {
                $skipped++;

                continue;
            }

            $source = $tree.'/'.$relative;

            if (! is_file($source)) {
                $skipped++;

                continue;
            }

            AtomicFile::write($root.'/'.$relative, (string) file_get_contents($source));
            $restored++;
        }

        return ['name' => $name, 'restored' => $restored, 'skipped' => $skipped];
    }

    /**
     * @return list<string> names of the backups that were removed
     */
    public function prune(int $keep = 0): array
    {
        if ($keep <= 0) {
            return [];
        }

        $removed = [];

        foreach (array_slice($this->list(), $keep) as $backup) {
            $name = (string) ($backup['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $this->delete($name);
            $removed[] = $name;
        }

        return $removed;
    }

    public function delete(string $name): void
    {
        $name = $this->normalizeName($name);
        $directory = $this->paths->backupDirectory($name);

        if (! is_dir($directory)) {
            return;
        }

        $this->deleteTree($directory);
    }

    public function manifest(string $name): ?array
    {
        $path = $this->paths->backupManifest($this->normalizeName($name));

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Every file in the storage root except the backups themselves and the
     * lock files, keyed by absolute path with a slash-separated relative
     * path as the value.
     *
     * @return \Generator<string, string>
     */
    private function walk(): \Generator
    {
        $root = $this->paths->root();

        if (! is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $absolute = str_replace('\\', '/', $file->getPathname());
            $relative = ltrim(str_replace(str_replace('\\', '/', $root), '', $absolute), '/');

            if ($relative === '' || $this->isExcluded($relative)) {
                continue;
            }

            yield $absolute => $relative;
        }
    }

    /**
     * @return list<string>
     */
    private function walkTree(string $tree): array
    {
        if (! is_dir($tree)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $absolute = str_replace('\\', '/', $file->getPathname());
            $files[] = ltrim(str_replace(str_replace('\\', '/', $tree), '', $absolute), '/');
        }

        sort($files);

        return $files;
    }

    private function isExcluded(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        return $relative === 'backups'
            || str_starts_with($relative, 'backups/')
            || $relative === 'locks'
            || str_starts_with($relative, 'locks/');
    }

    private function normalizeName(string $name): string
    {
        $name = basename(trim($name));

        if ($name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('A backup name is required.');
        }

        if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('A backup name may only contain letters, digits, dot, dash and underscore.');
        }

        return $name;
    }

    private function normalizeRelative(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if ($relative === '' || str_contains($relative, '..')) {
            throw new \RuntimeException('A backup manifest contains an unsafe path.');
        }

        return $relative;
    }

    private function deleteTree(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($directory);
    }
}
