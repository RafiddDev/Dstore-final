<?php

namespace App\DStore\Security;

use App\DStore\Storage\Event;

/**
 * Attaches the node's signature to a locally produced event.
 */
final class EventSigner
{
    public function __construct(private readonly MessageSigner $signer) {}

    public function sign(Event $event): Event
    {
        return $event->withSignature($this->signer->sign($event->signableContent()));
    }
}
