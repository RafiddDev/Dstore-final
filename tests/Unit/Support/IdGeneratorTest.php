<?php

namespace Tests\Unit\Support;

use App\DStore\Support\IdGenerator;
use PHPUnit\Framework\TestCase;

class IdGeneratorTest extends TestCase
{
    public function test_configured_collection_prefix_is_used(): void
    {
        $generator = new IdGenerator(['users' => 'usr']);

        $this->assertSame('usr', $generator->prefix('users'));
    }

    public function test_unknown_collection_falls_back_to_a_three_letter_prefix(): void
    {
        $generator = new IdGenerator([]);

        $this->assertSame('pro', $generator->prefix('products'));
    }

    public function test_generated_ids_have_the_expected_shape(): void
    {
        $generator = new IdGenerator(['users' => 'usr']);

        $id = $generator->generate('users');

        $this->assertMatchesRegularExpression('/^usr_[0-9A-Za-z]{20}$/', $id);
    }

    public function test_generated_ids_are_unique(): void
    {
        $generator = new IdGenerator(['users' => 'usr']);

        $ids = [];

        for ($i = 0; $i < 500; $i++) {
            $ids[] = $generator->generate('users');
        }

        $this->assertCount(500, array_unique($ids));
    }
}
