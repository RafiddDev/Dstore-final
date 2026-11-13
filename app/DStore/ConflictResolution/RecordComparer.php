<?php

namespace App\DStore\ConflictResolution;

use App\DStore\Storage\RecordStore;
use App\DStore\Support\VersionVector;

/**
 * Compares the local state of a set of records with the state a peer claims.
 *
 * The comparison never looks at a clock: two versions are compared with the
 * version-vector rules, so the answer is the same on every node.
 */
final class RecordComparer
{
    public function __construct(private readonly RecordStore $records) {}

    /**
     * @param  list<string>  $recordIds
     * @param  array<string, array<string, int>>  $peerVersions
     * @return list<array<string, mixed>>
     */
    public function compare(string $collection, array $recordIds, array $peerVersions = []): array
    {
        $out = [];

        foreach ($recordIds as $recordId) {
            $document = $this->records->read($collection, $recordId);

            $version = $document?->version() ?? [];
            $peerVersion = $peerVersions[$recordId] ?? null;

            $out[] = [
                'record_id' => $recordId,
                'found' => $document !== null,
                'version' => $version,
                'writer' => $document?->writer() ?? '',
                'hash' => (string) ($document?->meta['hash'] ?? ''),
                'updated_at' => $document?->updatedAt() ?? '',
                'deleted' => (bool) ($document?->isDeleted() ?? false),
                'peer_version' => $peerVersion,
                'relation' => $this->relation($version, $peerVersion),
            ];
        }

        return $out;
    }

    /**
     * How the local version and the peer's version relate to each other.
     *
     * @param  array<string, int>  $local
     * @param  array<string, int>|null  $peer
     */
    public function relation(array $local, ?array $peer): string
    {
        if ($peer === null) {
            return 'unknown';
        }

        if ($local === []) {
            return 'missing-local';
        }

        if (VersionVector::equals($local, $peer)) {
            return 'in-sync';
        }

        if (VersionVector::dominates($local, $peer)) {
            return 'local-newer';
        }

        if (VersionVector::dominates($peer, $local)) {
            return 'peer-newer';
        }

        return 'concurrent';
    }
}
