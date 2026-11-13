<?php

namespace App\DStore\ConflictResolution;

use App\DStore\Storage\RecordDocument;
use App\DStore\Support\CanonicalJson;

/**
 * The default policy: the write with the higher version-vector sum wins, and
 * ties are broken by the writing node id.
 *
 * The loser is never thrown away — the applier keeps it in the record's
 * history so an operator can see what was overridden.
 */
final class VersionVectorLwwResolver implements ConflictResolver
{
    public function resolve(RecordDocument $local, RecordDocument $incoming): ResolvedConflict
    {
        $localKey = $this->key($local);
        $incomingKey = $this->key($incoming);

        $comparison = $this->compare($incomingKey, $localKey);

        if ($comparison > 0) {
            return new ResolvedConflict($incoming, $local, 'incoming version is greater');
        }

        if ($comparison < 0) {
            return new ResolvedConflict($local, $incoming, 'local version is greater');
        }

        // Identical rank: fall back to the canonical form of the version
        // vectors so the decision is still total and every node agrees.
        $localJson = CanonicalJson::encode($local->version());
        $incomingJson = CanonicalJson::encode($incoming->version());

        if (strcmp($incomingJson, $localJson) > 0) {
            return new ResolvedConflict($incoming, $local, 'version tie broken by canonical form');
        }

        return new ResolvedConflict($local, $incoming, 'version tie broken by canonical form');
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function key(RecordDocument $document): array
    {
        $version = $document->version();

        return [
            array_sum(array_map('intval', $version)),
            $document->writer(),
        ];
    }

    /**
     * @param  array{0: int, 1: string}  $a
     * @param  array{0: int, 1: string}  $b
     */
    private function compare(array $a, array $b): int
    {
        if ($a[0] !== $b[0]) {
            return $a[0] <=> $b[0];
        }

        return strcmp($a[1], $b[1]);
    }
}
