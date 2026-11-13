<?php

namespace App\Console\Commands\DStore;

use App\DStore\Network\RegistrationService;
use Illuminate\Console\Command;

class NodeEnrollCommand extends Command
{
    protected $signature = 'dstore:node:enroll {peer? : Enrol with this peer, or all peers when omitted}';

    protected $description = 'Register this node with its peers and pin their public keys';

    public function handle(RegistrationService $registration): int
    {
        $peerId = $this->argument('peer');

        if ($peerId !== null) {
            $response = $registration->enroll((string) $peerId);

            if (! $response->successful) {
                $this->error("Enrolment with {$peerId} failed: ".$response->error);

                return self::FAILURE;
            }

            $this->info("Enrolled with {$peerId}.");
            $this->line('Peer fingerprint: '.(string) $response->body('node.fingerprint', ''));

            return self::SUCCESS;
        }

        $results = $registration->enrollAll();
        $failed = 0;

        foreach ($results as $id => $response) {
            if ($response->successful) {
                $this->info("Enrolled with {$id}.");
            } else {
                $failed++;
                $this->warn("Enrolment with {$id} failed: ".$response->error);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
