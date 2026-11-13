<?php

namespace App\Http\Controllers\DStore\V1;

use App\DStore\Replication\EventRelay;
use App\DStore\Security\EventVerifier;
use App\DStore\Storage\Event;
use App\DStore\Storage\EventInbox;
use App\DStore\Storage\EventLog;
use App\DStore\Synchronization\ApplyOutcome;
use App\DStore\Synchronization\EventApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends DStoreController
{
    /**
     * Accepts a batch of events from a peer.
     *
     * Every event is verified against the pinned key of the node that claims
     * it, written to the durable inbox, appended to the committed log and
     * only then applied to the records. A duplicate event is acknowledged
     * without being stored twice.
     */
    public function push(
        Request $request,
        EventInbox $inbox,
        EventLog $events,
        EventVerifier $verifier,
        EventApplier $applier,
        EventRelay $relay,
    ): JsonResponse {
        $incoming = $this->payload($request)['events'] ?? [];

        if (! is_array($incoming)) {
            return new JsonResponse(['ok' => false, 'error' => 'events must be an array.'], 422);
        }

        $accepted = 0;
        $duplicates = 0;
        $rejected = [];

        foreach ($incoming as $raw) {
            if (! is_array($raw)) {
                $rejected[] = ['event_id' => null, 'reason' => 'malformed event'];
                continue;
            }

            $event = Event::fromArray($raw);

            if ($event->eventId === '') {
                $rejected[] = ['event_id' => null, 'reason' => 'event has no id'];
                continue;
            }

            // Verified before anything is written: a forged event must never
            // reach the committed log.
            if (! $verifier->isTrustedOrigin($event->origin)) {
                $rejected[] = ['event_id' => $event->eventId, 'reason' => 'origin node is not a trusted peer'];
                continue;
            }

            if (! $verifier->verify($event)) {
                $rejected[] = ['event_id' => $event->eventId, 'reason' => 'invalid event signature'];
                continue;
            }

            if ($events->hasApplied($event->eventId)) {
                $duplicates++;
                continue;
            }

            // Durable first: the event is on disk before it can change a
            // record, so a crash mid-apply cannot lose it.
            $inbox->store($event);
            $events->append($event);

            $outcome = $applier->apply($event);

            if ($outcome->status === ApplyOutcome::REJECTED) {
                $rejected[] = ['event_id' => $event->eventId, 'reason' => $outcome->reason];
                continue;
            }

            $inbox->markApplied($event);

            // Keep the event circulating in the rest of the mesh.
            $relay->relay($event, $event->origin);

            if ($outcome->status === ApplyOutcome::DUPLICATE) {
                $duplicates++;
            } else {
                $accepted++;
            }
        }

        return new JsonResponse([
            'ok' => true,
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'rejected' => $rejected,
            'applied_vector' => $events->appliedVector(),
            'inbox_pending' => $inbox->count(),
        ]);
    }

    /**
     * Incremental pull: the events this node holds that the caller has not
     * seen yet.
     */
    public function index(Request $request, EventLog $events): JsonResponse
    {
        $after = $this->parseVector((string) $request->query('after', ''));

        $out = [];

        foreach ($events->all() as $event) {
            if ($event->seq <= ($after[$event->origin] ?? 0)) {
                continue;
            }

            $out[] = $event->toArray();
        }

        return new JsonResponse([
            'ok' => true,
            'events' => $out,
            'applied_vector' => $events->appliedVector(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function parseVector(string $after): array
    {
        $vector = [];

        foreach (array_filter(explode(',', $after)) as $part) {
            if (str_contains($part, ':')) {
                [$origin, $seq] = explode(':', $part, 2);
            } elseif (str_contains($part, '=')) {
                [$origin, $seq] = explode('=', $part, 2);
            } else {
                continue;
            }

            $vector[trim($origin)] = (int) trim($seq);
        }

        return $vector;
    }
}
