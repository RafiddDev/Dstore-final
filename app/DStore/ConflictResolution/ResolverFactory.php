<?php

namespace App\DStore\ConflictResolution;

/**
 * Builds the resolver the node is configured to use.
 */
final class ResolverFactory
{
    public const POLICY_VECTOR_LWW = 'vector-lww';

    public const POLICY_NODE_PRIORITY = 'node-priority';

    public const POLICY_MERGE = 'merge';

    /**
     * @param  list<string>  $priority  node ids, most trusted first
     */
    public static function make(string $policy, array $priority = []): ConflictResolver
    {
        $default = new VersionVectorLwwResolver();

        return match ($policy) {
            self::POLICY_NODE_PRIORITY => new NodePriorityResolver($priority, $default),
            self::POLICY_MERGE => new MergeResolver($default),
            default => $default,
        };
    }

    /**
     * @return list<string>
     */
    public static function policies(): array
    {
        return [
            self::POLICY_VECTOR_LWW,
            self::POLICY_NODE_PRIORITY,
            self::POLICY_MERGE,
        ];
    }
}
