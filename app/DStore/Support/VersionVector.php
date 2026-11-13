<?php

namespace App\DStore\Support;

final class VersionVector
{
    public static function increment(array $vector, string $nodeId): array
    {
        $vector[$nodeId] = (int) ($vector[$nodeId] ?? 0) + 1;
        ksort($vector);

        return $vector;
    }

    public static function merge(array $a, array $b): array
    {
        foreach ($b as $node => $counter) {
            $a[$node] = max((int) ($a[$node] ?? 0), (int) $counter);
        }

        ksort($a);

        return $a;
    }

    public static function dominates(array $a, array $b): bool
    {
        $strictlyGreater = false;

        foreach ($b as $node => $counter) {
            $mine = (int) ($a[$node] ?? 0);

            if ($mine < (int) $counter) {
                return false;
            }

            if ($mine > (int) $counter) {
                $strictlyGreater = true;
            }
        }

        foreach ($a as $node => $counter) {
            if (! array_key_exists($node, $b) && (int) $counter > 0) {
                $strictlyGreater = true;
            }
        }

        return $strictlyGreater;
    }

    public static function concurrent(array $a, array $b): bool
    {
        return ! self::dominates($a, $b) && ! self::dominates($b, $a);
    }

    public static function sum(array $vector): int
    {
        $sum = 0;

        foreach ($vector as $counter) {
            $sum += (int) $counter;
        }

        return $sum;
    }

    public static function equals(array $a, array $b): bool
    {
        $a = array_map('intval', $a);
        $b = array_map('intval', $b);

        ksort($a);
        ksort($b);

        return $a === $b;
    }
}
