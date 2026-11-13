<?php

namespace Tests\Unit\Support;

use App\DStore\Support\VersionVector;
use PHPUnit\Framework\TestCase;

class VersionVectorTest extends TestCase
{
    public function test_increment_advances_only_the_owning_node(): void
    {
        $vector = VersionVector::increment(['pakistan' => 2, 'uae' => 1], 'pakistan');

        $this->assertSame(['pakistan' => 3, 'uae' => 1], $vector);
    }

    public function test_increment_starts_from_empty_vector(): void
    {
        $this->assertSame(['uk' => 1], VersionVector::increment([], 'uk'));
    }

    public function test_merge_keeps_the_maximum_counter_per_node(): void
    {
        $merged = VersionVector::merge(
            ['pakistan' => 3, 'uae' => 1],
            ['uae' => 5, 'uk' => 1]
        );

        $this->assertSame(['pakistan' => 3, 'uae' => 5, 'uk' => 1], $merged);
    }

    public function test_dominance_requires_every_counter_to_be_at_least_equal(): void
    {
        $this->assertTrue(VersionVector::dominates(['a' => 2, 'b' => 1], ['a' => 1, 'b' => 1]));
        $this->assertTrue(VersionVector::dominates(['a' => 1, 'b' => 2], ['a' => 1, 'b' => 1]));
        $this->assertFalse(VersionVector::dominates(['a' => 1], ['a' => 2]));
        $this->assertFalse(VersionVector::dominates(['a' => 1], ['a' => 1]));
    }

    public function test_concurrent_versions_are_detected(): void
    {
        $this->assertTrue(VersionVector::concurrent(['a' => 2, 'b' => 0], ['a' => 1, 'b' => 1]));
        $this->assertFalse(VersionVector::concurrent(['a' => 2, 'b' => 1], ['a' => 1, 'b' => 1]));
    }

    public function test_sum_counts_all_counters(): void
    {
        $this->assertSame(6, VersionVector::sum(['a' => 2, 'b' => 4]));
    }
}
