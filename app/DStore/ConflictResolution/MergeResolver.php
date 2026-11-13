<?php

namespace App\DStore\ConflictResolution;

use App\DStore\Storage\RecordDocument;
use App\DStore\Support\CanonicalJson;

/**
 * Merges two concurrent writes field by field instead of throwing one away.
 *
 * A field that only one side changed is taken from that side. A field both
 * sides changed is decided by the fallback policy, exactly as the record
 * itself would be, so the two nodes that hold the same pair of versions
 * always build the same merged record.
 *
 * The merge is symmetric: it does not matter which side is called local.
 */
final class MergeResolver implements ConflictResolver
{
    public function __construct(private readonly ConflictResolver $fallback) {}

    public function resolve(RecordDocument $local, RecordDocument $incoming): ResolvedConflict
    {
        $decision = $this->fallback->resolve($local, $incoming);

        $incomingWon = $this->incomingWon($decision, $local, $incoming);

        $data = $this->mergeData($local->data, $incoming->data, $incomingWon);

        $meta = $this->mergeMeta($local, $incoming, $decision->winner);

        $merged = new RecordDocument($local->recordId, $local->collection, $meta, $data);

        return new ResolvedConflict(
            $merged,
            $decision->loser,
            'merged concurrent writes ('.$decision->reason.')',
            [$local, $incoming],
        );
    }

    /**
     * Whether the fallback picked the incoming side.
     *
     * The comparison is made on the versions and not on the object identity:
     * the two nodes hold the two sides in opposite roles, so the identity of
     * the winning object differs while the side it represents does not.
     */
    private function incomingWon(ResolvedConflict $decision, RecordDocument $local, RecordDocument $incoming): bool
    {
        $winner = CanonicalJson::encode($decision->winner->version());
        $localVersion = CanonicalJson::encode($local->version());
        $incomingVersion = CanonicalJson::encode($incoming->version());

        if ($winner === $localVersion && $winner === $incomingVersion) {
            return false;
        }

        return $winner === $incomingVersion;
    }

    private function mergeData(array $local, array $incoming, bool $incomingWon): array
    {
        $merged = $local;

        foreach ($incoming as $key => $value) {
            if (! array_key_exists($key, $merged)) {
                $merged[$key] = $value;

                continue;
            }

            if ($merged[$key] === $value) {
                continue;
            }

            $merged[$key] = $incomingWon ? $value : $merged[$key];
        }

        return (array) CanonicalJson::normalize($merged);
    }

    private function mergeMeta(RecordDocument $local, RecordDocument $incoming, RecordDocument $winner): array
    {
        $meta = $local->meta;

        $meta['created_at'] = $this->earliest($local->createdAt(), $incoming->createdAt());
        $meta['updated_at'] = $winner->updatedAt();
        $meta['deleted'] = $local->isDeleted() || $incoming->isDeleted();
        $meta['writer'] = $winner->writer();
        $meta['history'] = RecordDocument::unionHistory($local->history(), $incoming->history());

        return $meta;
    }

    private function earliest(string $a, string $b): string
    {
        if ($a === '') {
            return $b;
        }

        if ($b === '') {
            return $a;
        }

        return $a <= $b ? $a : $b;
    }
}
