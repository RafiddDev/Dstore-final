<?php

namespace App\Console\Commands\DStore;

use App\DStore\Storage\Contracts\StorageEngineInterface;
use Illuminate\Console\Command;

class RecordCommand extends Command
{
    protected $signature = 'dstore:record {action} {collection} {record?} {--payload=} {--id=}';

    protected $description = 'Manage D-STORE records from the command line';

    public function handle(StorageEngineInterface $engine): int
    {
        $action = (string) $this->argument('action');
        $collection = (string) $this->argument('collection');
        $recordId = $this->argument('record');

        return match ($action) {
            'create' => $this->create($engine, $collection),
            'list' => $this->listRecords($engine, $collection),
            'show' => $this->show($engine, $collection, (string) $recordId),
            'delete' => $this->delete($engine, $collection, (string) $recordId),
            default => $this->fail("Unknown action [{$action}]. Use create, list, show or delete."),
        };
    }

    private function create(StorageEngineInterface $engine, string $collection): int
    {
        $payload = json_decode((string) $this->option('payload'), true);

        if (! is_array($payload)) {
            return $this->fail('The --payload option must be valid JSON.');
        }

        $record = $engine->create($collection, $payload, $this->option('id') ?: null);

        $this->info("Created {$collection}/{$record->recordId}");

        return self::SUCCESS;
    }

    private function listRecords(StorageEngineInterface $engine, string $collection): int
    {
        $records = $engine->all($collection);

        if ($records === []) {
            $this->line("No records in collection [{$collection}].");

            return self::SUCCESS;
        }

        foreach ($records as $record) {
            $this->line(sprintf(
                '%s  v=%s  %s',
                $record->recordId,
                json_encode($record->version()),
                json_encode($record->data),
            ));
        }

        return self::SUCCESS;
    }

    private function show(StorageEngineInterface $engine, string $collection, string $recordId): int
    {
        $record = $engine->read($collection, $recordId);

        if ($record === null) {
            return $this->fail("Record [{$recordId}] not found in [{$collection}].");
        }

        $this->line(json_encode($record->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function delete(StorageEngineInterface $engine, string $collection, string $recordId): int
    {
        if (! $engine->delete($collection, $recordId)) {
            return $this->fail("Record [{$recordId}] was already deleted.");
        }

        $this->info("Deleted {$collection}/{$recordId}");

        return self::SUCCESS;
    }
}
