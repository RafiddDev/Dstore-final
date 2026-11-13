<?php

namespace App\Console\Commands\DStore;

use App\DStore\Security\EventSigner;
use App\DStore\Storage\AtomicFile;
use App\DStore\Storage\Event;
use App\DStore\Storage\EventLog;
use App\DStore\Storage\EventQuarantine;
use App\DStore\Storage\HealthState;
use App\DStore\Support\CanonicalJson;
use Illuminate\Console\Command;

/**
 * Signs the events in this node's own log that were never signed.
 *
 * An unsigned event cannot be verified by any peer, so it blocks the
 * replication stream behind it. The content of such an event is still
 * tamper-evident — the event id is a hash of the content — so the node can
 * safely vouch for it again with its own key.
 *
 * An event that carries a signature which does not verify is a different
 * matter: that points at tampering, so the command reports it and refuses
 * to touch it.
 */
class EventRepairCommand extends Command
{
    protected $signature = 'dstore:events:repair
        {--dry-run : Report what would be signed without changing anything}';

    protected $description = "Sign the unsigned events in this node's own log so peers can verify them";

    public function handle(
        EventLog $events,
        EventSigner $signer,
        EventQuarantine $quarantine,
        HealthState $health,
    ): int {
        $dryRun = (bool) $this->option('dry-run');

        $peers = $events->pendingPeers();

        $unsigned = 0;
        $tampered = 0;
        $signed = 0;
        $released = 0;

        foreach ($events->all() as $event) {
            $quarantined = $quarantine->has($event->eventId);

            if ($event->isSigned()) {
                // The event was refused before it could be signed. Now that
                // it carries a signature it can go back into the queue.
                if ($quarantined && ! $dryRun) {
                    $this->release($event, $peers, $events, $quarantine, $health);
                    $released++;
                }

                continue;
            }

            if (! $event->idMatchesContent()) {
                $tampered++;

                $this->warn(sprintf(
                    'tampered  %s  the content no longer matches the event id',
                    $event->eventId,
                ));

                continue;
            }

            $unsigned++;

            if ($dryRun) {
                $this->line(sprintf('would sign %s', $event->eventId));

                continue;
            }

            $repaired = $signer->sign($event);

            AtomicFile::write(
                $events->pathFor($event->origin, $event->seq),
                CanonicalJson::encode($repaired->toArray()),
            );

            foreach ($peers as $peerId) {
                if ($quarantined) {
                    $events->enqueuePending($peerId, $repaired);

                    continue;
                }

                // The queue holds its own copy of the event, so the signed
                // version has to go there too.
                $events->refreshPending($peerId, $repaired);
            }

            if ($quarantined) {
                $this->release($repaired, $peers, $events, $quarantine, $health);
                $released++;
            }

            $signed++;

            $this->info(sprintf('signed    %s', $repaired->eventId));
        }

        $this->newLine();
        $this->line("Unsigned events: {$unsigned}");
        $this->line("Signed now:      {$signed}");
        $this->line("Released from quarantine: {$released}");
        $this->line("Tampered:        {$tampered}");

        if ($tampered > 0) {
            $this->warn('Tampered events were left untouched. Restore them from a backup.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  string[]  $peers
     */
    private function release(
        Event $event,
        array $peers,
        EventLog $events,
        EventQuarantine $quarantine,
        HealthState $health,
    ): void {
        foreach ($peers as $peerId) {
            $events->enqueuePending($peerId, $event);
        }

        $quarantine->forget($event->eventId);
        $health->resolveMatching($event->eventId);
    }
}
