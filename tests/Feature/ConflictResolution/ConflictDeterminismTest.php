<?php

namespace Tests\Feature\ConflictResolution;

use App\DStore\Security\KeyManager;
use App\DStore\Security\MessageSigner;
use App\DStore\Security\PeerRegistry;
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\Event;
use App\DStore\Storage\RecordDocument;
use App\DStore\Support\CanonicalJson;
use App\DStore\Synchronization\EventApplier;
use Tests\Support\BuildsPeerEvents;
use Tests\TestCase;

/**
 * The same two concurrent writes, resolved from either side, must leave the
 * same record behind. If they did not, the mesh would drift apart.
 */
class ConflictDeterminismTest extends TestCase
{
    use BuildsPeerEvents;

    private function applier(): EventApplier
    {
        return $this->app->make(EventApplier::class);
    }

    /**
     * A write produced by this node, signed with this node's own key.
     */
    private function localEvent(string $recordId = 'usr_x', array $data = ['name' => 'Local']): Event
    {
        $manager = $this->app->make(KeyManager::class);
        $manager->publicKey();

        $this->app->make(PeerRegistry::class)->approve(
            'test-node',
            $manager->publicKey(),
            'http://127.0.0.1:8001',
        );

        $document = RecordDocument::make('users', $recordId, $data, ['test-node' => 1], '2026-11-12T10:00:00Z');

        $event = Event::compose('test-node', 1, Event::TYPE_CREATED, $document, ['test-node' => 1], '2026-11-12T10:00:00Z', null);

        return $event->withSignature(
            (new MessageSigner($manager))->sign($event->signableContent()),
        );
    }

    /**
     * A write produced by the peer, signed with the peer's own key.
     */
    private function peerEvent(string $recordId = 'usr_x', array $data = ['name' => 'Remote']): Event
    {
        $peer = $this->peerKeyManager();
        $this->approvePeer($peer);

        return $this->signedEvent($peer, 'uae', 1, 'users', $recordId, $data, ['uae' => 1]);
    }

    private function switchStorage(string $path): void
    {
        config(['dstore.storage.path' => $path]);

        $this->refreshDStoreBindings();
    }

    private function record(string $recordId = 'usr_x'): array
    {
        $document = $this->app->make(StorageEngineInterface::class)->read('users', $recordId);

        $this->assertNotNull($document);

        return $document->toArray();
    }

    /**
     * The record as the nodes compare it: canonical, so a different key order
     * in the file is not mistaken for a different record.
     */
    private function canonical(array $record): string
    {
        return CanonicalJson::encode($record);
    }

    public function test_the_local_side_first_produces_a_record(): void
    {
        $this->applier()->apply($this->localEvent());
        $this->applier()->apply($this->peerEvent());

        $record = $this->record();

        $this->assertSame(['test-node' => 1, 'uae' => 1], $record['meta']['version']);
        $this->assertSame('Remote', $record['data']['name']);
    }

    public function test_the_peer_side_first_produces_the_same_record(): void
    {
        $this->switchStorage($this->storagePath('first'));

        $this->applier()->apply($this->localEvent());
        $this->applier()->apply($this->peerEvent());

        $localRecord = $this->record();

        // The same two writes, arriving the other way round.
        $this->switchStorage($this->storagePath('second'));

        $this->applier()->apply($this->peerEvent());
        $this->applier()->apply($this->localEvent());

        $peerRecord = $this->record();

        $this->assertSame($this->canonical($localRecord), $this->canonical($peerRecord));
        $this->assertSame($localRecord['meta']['hash'], $peerRecord['meta']['hash']);
    }

    public function test_the_merge_policy_produces_the_same_record_from_either_side(): void
    {
        config(['dstore.consistency.policy' => 'merge']);

        $this->refreshDStoreBindings();

        $this->switchStorage($this->storagePath('first'));

        $this->applier()->apply($this->localEvent('usr_x', ['name' => 'Local', 'city' => 'Karachi']));
        $this->applier()->apply($this->peerEvent('usr_x', ['name' => 'Remote']));

        $localRecord = $this->record();

        $this->switchStorage($this->storagePath('second'));

        $this->applier()->apply($this->peerEvent('usr_x', ['name' => 'Remote']));
        $this->applier()->apply($this->localEvent('usr_x', ['name' => 'Local', 'city' => 'Karachi']));

        $peerRecord = $this->record();

        $this->assertSame($this->canonical($localRecord), $this->canonical($peerRecord));
        $this->assertSame('Remote', $peerRecord['data']['name']);
        $this->assertSame('Karachi', $peerRecord['data']['city']);
    }

    public function test_a_conflict_keeps_the_losing_state_in_the_history(): void
    {
        $this->applier()->apply($this->localEvent());
        $this->applier()->apply($this->peerEvent());

        $record = $this->record();

        $this->assertCount(1, $record['meta']['history']);

        $versions = array_map(
            fn (array $entry): string => json_encode($entry['version']),
            $record['meta']['history'],
        );

        $this->assertContains('{"test-node":1}', $versions);
    }

    public function test_the_merge_policy_keeps_both_states_in_the_history(): void
    {
        config(['dstore.consistency.policy' => 'merge']);

        $this->refreshDStoreBindings();

        $this->applier()->apply($this->localEvent());
        $this->applier()->apply($this->peerEvent());

        $record = $this->record();

        $this->assertCount(2, $record['meta']['history']);

        $versions = array_map(
            fn (array $entry): string => json_encode($entry['version']),
            $record['meta']['history'],
        );

        $this->assertContains('{"test-node":1}', $versions);
        $this->assertContains('{"uae":1}', $versions);
    }

    public function test_the_history_order_does_not_depend_on_which_side_arrived_first(): void
    {
        $this->switchStorage($this->storagePath('first'));

        $this->applier()->apply($this->localEvent());
        $this->applier()->apply($this->peerEvent());

        $first = $this->record()['meta']['history'];

        $this->switchStorage($this->storagePath('second'));

        $this->applier()->apply($this->peerEvent());
        $this->applier()->apply($this->localEvent());

        $second = $this->record()['meta']['history'];

        $this->assertSame($first, $second);
    }
}
