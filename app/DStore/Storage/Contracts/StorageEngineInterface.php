<?php

namespace App\DStore\Storage\Contracts;

use App\DStore\Storage\RecordDocument;

interface StorageEngineInterface
{
    public function create(string $collection, array $data, ?string $recordId = null): RecordDocument;

    public function read(string $collection, string $recordId): ?RecordDocument;

    public function readOrFail(string $collection, string $recordId): RecordDocument;

    public function update(string $collection, string $recordId, array $data): RecordDocument;

    public function delete(string $collection, string $recordId): bool;

    public function all(string $collection, bool $includeDeleted = false): array;

    public function count(string $collection): int;

    public function history(string $collection, string $recordId): array;

    public function stats(): array;
}
