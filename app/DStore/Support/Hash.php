<?php

namespace App\DStore\Support;

final class Hash
{
    public static function sha256(string $data): string
    {
        return hash('sha256', $data);
    }

    public static function file(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        return hash_file('sha256', $path) ?: '';
    }
}
