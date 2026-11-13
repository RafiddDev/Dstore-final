<?php

namespace App\DStore\Network;

/**
 * A peer this node can talk to, together with whether it is trusted.
 */
final class PeerEndpoint
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly bool $trusted = false,
        public readonly ?string $fingerprint = null,
    ) {}

    public function endpoint(string $path): string
    {
        return rtrim($this->url, '/').'/'.ltrim($path, '/');
    }
}
