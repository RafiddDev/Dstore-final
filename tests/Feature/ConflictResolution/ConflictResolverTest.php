<?php

namespace Tests\Feature\ConflictResolution;

use App\DStore\ConflictResolution\ConflictResolver;
use App\DStore\ConflictResolution\MergeResolver;
use App\DStore\ConflictResolution\NodePriorityResolver;
use App\DStore\ConflictResolution\ResolverFactory;
use App\DStore\ConflictResolution\VersionVectorLwwResolver;
use App\DStore\Storage\RecordDocument;
use Tests\TestCase;

class ConflictResolverTest extends TestCase
{
    private function document(string $writer, array $version, array $data): RecordDocument
    {
        return RecordDocument::make('users', 'usr_x', $data, $version, '2026-11-12T10:00:00Z')
            ->withWriter($writer);
    }

    public function test_the_factory_builds_the_policy_that_was_configured(): void
    {
        $this->assertInstanceOf(VersionVectorLwwResolver::class, ResolverFactory::make('vector-lww'));
        $this->assertInstanceOf(NodePriorityResolver::class, ResolverFactory::make('node-priority', ['pakistan']));
        $this->assertInstanceOf(MergeResolver::class, ResolverFactory::make('merge'));
    }

    public function test_an_unknown_policy_falls_back_to_the_version_vector_policy(): void
    {
        $this->assertInstanceOf(VersionVectorLwwResolver::class, ResolverFactory::make('nonsense'));
    }

    public function test_the_configured_policy_is_the_one_the_node_uses(): void
    {
        config(['dstore.consistency.policy' => 'merge']);

        $this->refreshDStoreBindings();

        $this->assertInstanceOf(MergeResolver::class, $this->app->make(ConflictResolver::class));
    }

    public function test_the_node_priority_policy_picks_the_ranked_node(): void
    {
        $resolver = new NodePriorityResolver(['pakistan', 'uae'], new VersionVectorLwwResolver());

        $local = $this->document('pakistan', ['pakistan' => 1], ['name' => 'Local']);
        $incoming = $this->document('uae', ['uae' => 1], ['name' => 'Remote']);

        $resolved = $resolver->resolve($local, $incoming);

        $this->assertSame(['name' => 'Local'], $resolved->winner->data);
        $this->assertSame('node priority', $resolved->reason);
    }

    public function test_the_node_priority_policy_decides_the_same_way_on_both_nodes(): void
    {
        $resolver = new NodePriorityResolver(['pakistan', 'uae'], new VersionVectorLwwResolver());

        $local = $this->document('pakistan', ['pakistan' => 1], ['name' => 'Local']);
        $incoming = $this->document('uae', ['uae' => 1], ['name' => 'Remote']);

        $this->assertSame(
            $resolver->resolve($local, $incoming)->winner->data,
            $resolver->resolve($incoming, $local)->winner->data,
        );
    }

    public function test_the_node_priority_policy_falls_back_when_neither_side_is_ranked(): void
    {
        $resolver = new NodePriorityResolver(['pakistan'], new VersionVectorLwwResolver());

        $local = $this->document('uk', ['uk' => 1], ['name' => 'Local']);
        $incoming = $this->document('usa', ['usa' => 1], ['name' => 'Remote']);

        $resolved = $resolver->resolve($local, $incoming);

        $this->assertSame(
            $resolver->resolve($local, $incoming)->winner->data,
            $resolved->winner->data,
        );

        // The fallback decides: the greater node id wins.
        $this->assertSame(['name' => 'Remote'], $resolved->winner->data);
    }

    public function test_the_merge_policy_keeps_the_fields_only_one_side_changed(): void
    {
        $resolver = new MergeResolver(new VersionVectorLwwResolver());

        $local = $this->document('pakistan', ['pakistan' => 1], ['name' => 'Local', 'city' => 'Karachi']);
        $incoming = $this->document('uae', ['uae' => 1], ['name' => 'Remote', 'country' => 'UAE']);

        $resolved = $resolver->resolve($local, $incoming);

        $this->assertSame([
            'city' => 'Karachi',
            'country' => 'UAE',
            'name' => 'Remote',
        ], $resolved->winner->data);
    }

    public function test_the_merge_policy_merges_the_same_way_on_both_nodes(): void
    {
        $resolver = new MergeResolver(new VersionVectorLwwResolver());

        $local = $this->document('pakistan', ['pakistan' => 1], ['name' => 'Local', 'city' => 'Karachi']);
        $incoming = $this->document('uae', ['uae' => 1], ['name' => 'Remote', 'country' => 'UAE']);

        $merged = $resolver->resolve($local, $incoming)->winner;
        $swapped = $resolver->resolve($incoming, $local)->winner;

        $this->assertSame($merged->data, $swapped->data);
        $this->assertSame($merged->meta['history'], $swapped->meta['history']);
        $this->assertSame($merged->meta['writer'], $swapped->meta['writer']);
        $this->assertSame($merged->meta['created_at'], $swapped->meta['created_at']);
        $this->assertSame($merged->meta['updated_at'], $swapped->meta['updated_at']);
    }

    public function test_the_merge_policy_keeps_both_sides_in_the_history(): void
    {
        $resolver = new MergeResolver(new VersionVectorLwwResolver());

        $local = $this->document('pakistan', ['pakistan' => 1], ['name' => 'Local']);
        $incoming = $this->document('uae', ['uae' => 1], ['name' => 'Remote']);

        $resolved = $resolver->resolve($local, $incoming);

        $this->assertCount(2, $resolved->superseded);
        $this->assertStringContainsString('merged concurrent writes', $resolved->reason);
    }

    public function test_the_merge_policy_treats_a_delete_as_a_delete(): void
    {
        $resolver = new MergeResolver(new VersionVectorLwwResolver());

        $local = $this->document('pakistan', ['pakistan' => 1], ['name' => 'Local']);
        $incoming = $this->document('uae', ['uae' => 1], ['name' => 'Remote'])->withDeleted(true, ['uae' => 1], '2026-11-12T10:00:00Z');

        $resolved = $resolver->resolve($local, $incoming);

        $this->assertTrue($resolved->winner->isDeleted());
    }

    public function test_the_version_vector_policy_never_looks_at_a_clock(): void
    {
        $resolver = new VersionVectorLwwResolver();

        $early = RecordDocument::make('users', 'usr_x', ['name' => 'Early'], ['uae' => 1], '2020-01-01T00:00:00Z')
            ->withWriter('uae');
        $late = RecordDocument::make('users', 'usr_x', ['name' => 'Late'], ['pakistan' => 1], '2030-01-01T00:00:00Z')
            ->withWriter('pakistan');

        // The later timestamp loses: the version vector decides.
        $this->assertSame(['name' => 'Early'], $resolver->resolve($early, $late)->winner->data);
    }
}
