<?php

namespace Tests\Unit\Support;

use App\DStore\Support\CanonicalJson;
use PHPUnit\Framework\TestCase;

class CanonicalJsonTest extends TestCase
{
    public function test_key_insertion_order_does_not_change_output(): void
    {
        $first = ['b' => 1, 'a' => ['z' => 1, 'y' => 2]];
        $second = ['a' => ['y' => 2, 'z' => 1], 'b' => 1];

        $this->assertSame(CanonicalJson::encode($first), CanonicalJson::encode($second));
    }

    public function test_list_order_is_preserved(): void
    {
        $this->assertSame('["b","a"]', CanonicalJson::encode(['b', 'a']));
    }

    public function test_slashes_and_unicode_are_not_escaped(): void
    {
        $encoded = CanonicalJson::encode(['name' => 'Zürich', 'url' => 'http://127.0.0.1:8001/api']);

        $this->assertSame('{"name":"Zürich","url":"http://127.0.0.1:8001/api"}', $encoded);
    }

    public function test_identical_content_produces_identical_hash_input(): void
    {
        $a = CanonicalJson::encode(['x' => 1, 'y' => ['a' => true, 'b' => null]]);
        $b = CanonicalJson::encode(['y' => ['b' => null, 'a' => true], 'x' => 1]);

        $this->assertSame(hash('sha256', $a), hash('sha256', $b));
    }
}
