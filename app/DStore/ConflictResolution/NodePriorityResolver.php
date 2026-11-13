<?php

namespace App\DStore\ConflictResolution;

use App\DStore\Storage\RecordDocument;

/**
 * Picks the winner by the rank the operator gave each node.
 *
 * The rank list is a plain ordered list of node ids; the node that comes
 * first wins. When neither side is ranked, or both are ranked equally, the
 * version-vector policy decides, so the outcome stays deterministic.
 */
final class NodePriorityResolver implements ConflictResolver
{
    /**
     * @param  list<string>  $priority  node ids, most trusted first
     */
    public function __construct(
        private readonly array $priority,
        private readonly ConflictResolver $fallback,
    ) {}

    public function resolve(RecordDocument $local, RecordDocument $incoming): ResolvedConflict
    {
        $localRank = $this->rank($local->writer());
        $incomingRank = $this->rank($incoming->writer());

        if ($localRank !== null && $incomingRank !== null) {
            if ($localRank !== $incomingRank) {
                return $localRank < $incomingRank
                    ? new ResolvedConflict($local, $incoming, 'node priority')
                    : new ResolvedConflict($incoming, $local, 'node priority');
            }
        } elseif ($localRank !== null) {
            return new ResolvedConflict($local, $incoming, 'node priority');
        } elseif ($incomingRank !== null) {
            return new ResolvedConflict($incoming, $local, 'node priority');
        }

        return $this->fallback->resolve($local, $incoming);
    }

    private function rank(string $writer): ?int
    {
        foreach ($this->priority as $index => $nodeId) {
            if ($nodeId === $writer) {
                return $index;
            }
        }

        return null;
    }
}
