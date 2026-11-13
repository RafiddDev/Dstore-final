<?php

namespace App\DStore\ConflictResolution;

use App\DStore\Storage\RecordDocument;

/**
 * Picks the winner of two concurrent writes to the same record.
 *
 * The decision must be a pure function of the two versions so that every node
 * reaches the same answer. Wall-clock timestamps are never part of it: the
 * clocks of independent servers are not synchronised.
 */
interface ConflictResolver
{
    public function resolve(RecordDocument $local, RecordDocument $incoming): ResolvedConflict;
}
