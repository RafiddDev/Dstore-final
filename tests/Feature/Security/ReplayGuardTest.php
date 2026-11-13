<?php

namespace Tests\Feature\Security;

use App\DStore\Security\ReplayGuard;
use App\DStore\Storage\StoragePaths;
use Tests\TestCase;

class ReplayGuardTest extends TestCase
{
    private function guard(int $window = 300, int $skew = 300): ReplayGuard
    {
        return new ReplayGuard($this->app->make(StoragePaths::class), $window, $skew);
    }

    public function test_a_fresh_message_is_accepted_once(): void
    {
        $guard = $this->guard();

        $this->assertTrue($guard->accept('msg-1', time()));
        $this->assertFalse($guard->accept('msg-1', time()));
    }

    public function test_different_message_ids_are_accepted(): void
    {
        $guard = $this->guard();

        $this->assertTrue($guard->accept('msg-1', time()));
        $this->assertTrue($guard->accept('msg-2', time()));
        $this->assertSame(2, $guard->count());
    }

    public function test_a_message_outside_the_window_is_refused(): void
    {
        $guard = $this->guard(300, 300);

        $this->assertFalse($guard->accept('old', time() - 301));
        $this->assertFalse($guard->accept('future', time() + 301));
        $this->assertTrue($guard->accept('fresh', time()));
    }

    public function test_a_message_inside_the_window_is_accepted(): void
    {
        $guard = $this->guard(300, 300);

        $this->assertTrue($guard->accept('edge', time() - 299));
        $this->assertTrue($guard->accept('edge2', time() + 299));
    }

    public function test_an_empty_message_id_is_refused(): void
    {
        $this->assertFalse($this->guard()->accept('', time()));
    }

    public function test_seen_messages_survive_a_restart(): void
    {
        $this->guard()->accept('msg-1', time());

        $this->assertTrue($this->guard()->seen('msg-1'));
        $this->assertFalse($this->guard()->accept('msg-1', time()));
    }

    public function test_old_entries_are_pruned_so_the_state_file_cannot_grow_forever(): void
    {
        $this->guard(300, 300)->accept('old', time() - 120);

        $this->assertTrue($this->guard(300, 300)->seen('old'));

        $this->guard(60, 60)->accept('new', time());

        $this->assertFalse($this->guard(60, 60)->seen('old'));
        $this->assertTrue($this->guard(60, 60)->seen('new'));
    }
}
