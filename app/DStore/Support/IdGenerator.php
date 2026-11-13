<?php

namespace App\DStore\Support;

final class IdGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function __construct(private readonly array $prefixes = []) {}

    public function generate(string $collection, int $length = 20): string
    {
        return $this->prefix($collection).'_'.$this->randomString($length);
    }

    public function prefix(string $collection): string
    {
        $configured = $this->prefixes[$collection] ?? null;

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $collection));

        return substr($slug !== '' ? $slug : 'rec', 0, 3);
    }

    private function randomString(int $length): string
    {
        $output = '';

        while (strlen($output) < $length) {
            $bytes = random_bytes($length);

            for ($i = 0, $total = strlen($bytes); $i < $total; $i++) {
                $value = ord($bytes[$i]) & 0x3F;

                if ($value < 62) {
                    $output .= self::ALPHABET[$value];

                    if (strlen($output) === $length) {
                        break 2;
                    }
                }
            }
        }

        return $output;
    }
}
