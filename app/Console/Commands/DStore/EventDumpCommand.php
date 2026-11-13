<?php

namespace App\Console\Commands\DStore;

use App\DStore\Storage\StoragePaths;
use Illuminate\Console\Command;

class EventDumpCommand extends Command
{
    protected $signature = 'dstore:event:dump {event_id : Event id, e.g. pakistan-00000001-1a2b3c4d}';

    protected $description = 'Print a stored event by id';

    public function handle(StoragePaths $paths): int
    {
        $eventId = (string) $this->argument('event_id');
        $directory = $paths->root().'/events/committed';

        if (! is_dir($directory)) {
            return $this->fail('No event log found.');
        }

        foreach (scandir($directory) ?: [] as $origin) {
            if (! is_dir($directory.'/'.$origin)) {
                continue;
            }

            foreach (scandir($directory.'/'.$origin) ?: [] as $file) {
                if (! str_ends_with($file, '.event')) {
                    continue;
                }

                $path = $directory.'/'.$origin.'/'.$file;
                $decoded = json_decode((string) file_get_contents($path), true);

                if (is_array($decoded) && ($decoded['event_id'] ?? null) === $eventId) {
                    $this->line(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                    return self::SUCCESS;
                }
            }
        }

        return $this->fail("Event [{$eventId}] not found.");
    }
}
