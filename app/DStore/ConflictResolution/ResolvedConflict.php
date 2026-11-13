<?php

namespace App\DStore\ConflictResolution;

use App\DStore\Storage\RecordDocument;

final class ResolvedConflict
{
    /**
     * @param  list<RecordDocument>  $superseded  the states that were replaced; the
     *                                           first one is the loser itself
     */
    public function __construct(
        public readonly RecordDocument $winner,
        public readonly RecordDocument $loser,
        public readonly string $reason,
        public readonly array $superseded = [],
    ) {}
}
