<?php

namespace Tests\Feature\Security;

use App\DStore\Security\BootstrapToken;
use Tests\TestCase;

class BootstrapTokenTest extends TestCase
{
    public function test_the_correct_token_is_accepted(): void
    {
        $token = new BootstrapToken('s3cret-token');

        $this->assertTrue($token->verify('s3cret-token'));
    }

    public function test_a_wrong_or_empty_token_is_refused(): void
    {
        $token = new BootstrapToken('s3cret-token');

        $this->assertFalse($token->verify('wrong'));
        $this->assertFalse($token->verify(''));
        $this->assertFalse($token->verify(null));
    }

    public function test_a_missing_configured_token_refuses_every_candidate(): void
    {
        $token = new BootstrapToken(null);

        $this->assertTrue($token->isRequired());
        $this->assertFalse($token->verify('anything'));
        $this->assertFalse($token->verify(null));
    }

    public function test_open_mode_accepts_any_candidate(): void
    {
        $token = new BootstrapToken('s3cret-token', BootstrapToken::MODE_OPEN);

        $this->assertFalse($token->isRequired());
        $this->assertTrue($token->verify(null));
        $this->assertTrue($token->verify('anything'));
    }

    public function test_the_token_value_is_never_exposed(): void
    {
        $token = new BootstrapToken('s3cret-token');

        $this->assertStringNotContainsString('s3cret-token', $token->fingerprint());
        $this->assertStringNotContainsString('s3cret-token', json_encode($token->fingerprint()));
    }
}
