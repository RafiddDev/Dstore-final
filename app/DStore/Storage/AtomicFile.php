<?php

namespace App\DStore\Storage;

use App\DStore\Storage\Exceptions\StorageException;

final class AtomicFile
{
    public static function write(string $path, string $contents): void
    {
        $directory = dirname($path);
        self::ensureDirectory($directory);

        $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));
        $handle = fopen($temporary, 'wb');

        if ($handle === false) {
            throw new StorageException("Unable to open temporary file for write: {$temporary}");
        }

        try {
            fwrite($handle, $contents);
            fflush($handle);
            @fsync($handle);
        } finally {
            fclose($handle);
        }

        if (! rename($temporary, $path)) {
            @unlink($temporary);

            throw new StorageException("Atomic rename failed for path: {$path}");
        }

        self::syncDirectory($directory);
    }

    public static function append(string $path, string $contents): void
    {
        self::ensureDirectory(dirname($path));

        $handle = fopen($path, 'ab');

        if ($handle === false) {
            throw new StorageException("Unable to open file for append: {$path}");
        }

        try {
            flock($handle, LOCK_EX);
            fwrite($handle, $contents);
            fflush($handle);
            @fsync($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    public static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new StorageException("Unable to create directory: {$directory}");
        }
    }

    private static function syncDirectory(string $directory): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        $handle = @fopen($directory, 'r');

        if ($handle === false) {
            return;
        }

        @fsync($handle);
        fclose($handle);
    }
}
