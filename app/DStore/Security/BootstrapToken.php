<?php

namespace App\DStore\Security;

/**
 * The out-of-band secret used to enrol a node for the first time.
 *
 * Once a node is enrolled its Ed25519 key is what authorises it, so the
 * bootstrap token only guards the enrolment door, not every message.
 */
final class BootstrapToken
{
    public const MODE_OPEN = 'open';

    public const MODE_TOKEN = 'token';

    public function __construct(
        private readonly ?string $token,
        private readonly string $mode = self::MODE_TOKEN,
    ) {}

    public function isRequired(): bool
    {
        return $this->mode !== self::MODE_OPEN;
    }

    public function verify(?string $candidate): bool
    {
        if (! $this->isRequired()) {
            return true;
        }

        if ($this->token === null || $this->token === '') {
            return false;
        }

        if ($candidate === null || $candidate === '') {
            return false;
        }

        return hash_equals($this->token, $candidate);
    }

    /**
     * The token is never returned to a client, only a boolean answer.
     */
    public function fingerprint(): string
    {
        return $this->token === null || $this->token === ''
            ? ''
            : substr(hash('sha256', $this->token), 0, 16);
    }

    /**
     * The raw token, for outbound enrolment requests only. It travels in the
     * request body of `POST /nodes/register` and is never written to a
     * response, a log line or the dashboard.
     */
    public function reveal(): ?string
    {
        return $this->token;
    }
}
