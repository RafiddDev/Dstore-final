<?php

namespace App\DStore\Support;

final class Timestamp
{
    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
