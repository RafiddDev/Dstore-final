<?php

namespace App\DStore\Storage;

use App\DStore\Storage\Exceptions\StorageLockException;

final class FileLocker
{
    public function __construct(private readonly StoragePaths $paths) {}

    public function withExclusiveLock(string $key, callable $callback, float $timeout = 5.0): mixed
    {
        return $this->lock($key, LOCK_EX, $callback, $timeout);
    }

    public function withSharedLock(string $key, callable $callback, float $timeout = 5.0): mixed
    {
        return $this->lock($key, LOCK_SH, $callback, $timeout);
    }

    private function lock(string $key, int $mode, callable $callback, float $timeout): mixed
    {
        AtomicFile::ensureDirectory($this->paths->locks());

        $path = $this->paths->lock($key);
        $handle = fopen($path, 'c+');

        if ($handle === false) {
            throw new StorageLockException("Unable to open lock file for key: {$key}");
        }

        $deadline = microtime(true) + $timeout;
        $acquired = false;

        try {
            while (true) {
                if (flock($handle, $mode | LOCK_NB)) {
                    $acquired = true;

                    break;
                }

                if (microtime(true) >= $deadline) {
                    break;
                }

                usleep(20000);
            }

            if (! $acquired) {
                throw new StorageLockException("Timed out after {$timeout}s acquiring lock: {$key}");
            }

            return $callback();
        } finally {
            if ($acquired) {
                flock($handle, LOCK_UN);
            }

            fclose($handle);
        }
    }
}
