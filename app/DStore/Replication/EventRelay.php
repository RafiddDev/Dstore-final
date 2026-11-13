<?php

namespace App\DStore\Replication;

use App\DStore\Network\PeerDirectory;
use App\DStore\Storage\Event;
use App\DStore\Storage\EventLog;

/**
 * Passes an event on to the peers that have not seen it yet.
 *
 * A node that learns an event from one peer must keep its own copy in the
 * committed log and offer it to the rest of the mesh. Otherwise a peer that
 * goes offline would take the event out of circulation, and a node that
 * applied the event would advertise it in its applied vector while being
 * unable to serve it.
 */
final class EventRelay
{
    public function __construct(
        private readonly EventLog $events,
        private readonly PeerDirectory $directory,
    ) {}

    /**
     * Queues the event for every trusted peer except the given one.
     *
     * @param  string|null  $except  the peer the event came from
     */
    public function relay(Event $event, ?string $except = null): void
    {
        foreach ($this->directory->trusted() as $peerId => $peer) {
            if ($peerId === $except) {
                continue;
            }

            $this->events->enqueuePending($peerId, $event);
        }
    }
}
