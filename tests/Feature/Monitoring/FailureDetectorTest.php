<?php

namespace Tests\Feature\Monitoring;

use App\DStore\Monitoring\FailureDetector;
use App\DStore\Monitoring\PeerStatusStore;
use App\DStore\Storage\AtomicFile;
use App\DStore\Storage\StoragePaths;
use App\DStore\Support\CanonicalJson;
use App\DStore\Support\Timestamp;
use Tests\TestCase;

class FailureDetectorTest extends TestCase
{
    private function detector(): FailureDetector
    {
        return $this->app->make(FailureDetector::class);
    }

    private function statuses(): PeerStatusStore
    {
        return $this->app->make(PeerStatusStore::class);
    }

    public function test_a_peer_that_has_never_been_seen_is_unknown(): void
    {
        $decision = $this->detector()->evaluate('uae');

        $this->assertSame(PeerStatusStore::STATE_UNKNOWN, $decision['state']);
        $this->assertFalse($decision['healthy']);
    }

    public function test_a_fresh_heartbeat_is_online(): void
    {
        $this->statuses()->recordHeartbeat('uae', 12.5);

        $decision = $this->detector()->evaluate('uae');

        $this->assertSame(PeerStatusStore::STATE_ONLINE, $decision['state']);
        $this->assertTrue($decision['healthy']);
    }

    public function test_a_peer_that_reports_corruption_is_degraded_but_still_healthy(): void
    {
        $this->statuses()->recordHeartbeat('uae', 9.0, PeerStatusStore::STATE_DEGRADED, ['degraded' => true]);

        $decision = $this->detector()->evaluate('uae');

        $this->assertSame(PeerStatusStore::STATE_DEGRADED, $decision['state']);
        $this->assertFalse($decision['healthy']);
    }

    public function test_one_miss_is_unreachable_not_offline(): void
    {
        $this->statuses()->recordFailure('uae', 'timeout');

        $decision = $this->detector()->evaluate('uae');

        $this->assertSame(PeerStatusStore::STATE_UNREACHABLE, $decision['state']);
        $this->assertFalse($decision['healthy']);
    }

    public function test_three_consecutive_misses_become_offline(): void
    {
        $this->statuses()->recordFailure('uae', 'timeout');
        $this->statuses()->recordFailure('uae', 'timeout');
        $this->statuses()->recordFailure('uae', 'timeout');

        $decision = $this->detector()->evaluate('uae');

        $this->assertSame(PeerStatusStore::STATE_OFFLINE, $decision['state']);
        $this->assertStringContainsString('3 consecutive', $decision['reason']);
    }

    public function test_offline_is_persisted_by_refresh_and_recovers_on_heartbeat(): void
    {
        $this->statuses()->recordFailure('uae', 'timeout');
        $this->statuses()->recordFailure('uae', 'timeout');
        $this->statuses()->recordFailure('uae', 'timeout');

        $this->detector()->refresh(['uae']);

        $this->assertSame(PeerStatusStore::STATE_OFFLINE, $this->statuses()->state('uae'));

        $this->statuses()->recordHeartbeat('uae', 4.0);

        $this->assertSame(PeerStatusStore::STATE_ONLINE, $this->detector()->evaluate('uae')['state']);
    }

    public function test_an_overdue_online_status_degrades_to_unreachable(): void
    {
        $paths = $this->app->make(StoragePaths::class);

        AtomicFile::write($paths->peerStatusFile(), CanonicalJson::encode([
            'uae' => [
                'peer' => 'uae',
                'state' => PeerStatusStore::STATE_ONLINE,
                'last_seen_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 600),
                'consecutive_failures' => 0,
                'degraded' => false,
                'destroyed_at' => null,
            ],
        ]));

        $decision = $this->detector()->evaluate('uae');

        $this->assertSame(PeerStatusStore::STATE_UNREACHABLE, $decision['state']);
        $this->assertGreaterThan(500, $decision['silent_for']);
    }

    public function test_a_destroyed_peer_stays_destroyed_until_cleared(): void
    {
        $this->statuses()->markDestroyed('uae');

        $this->assertSame(PeerStatusStore::STATE_DESTROYED, $this->detector()->evaluate('uae')['state']);

        $this->detector()->refresh(['uae']);

        $this->assertSame(PeerStatusStore::STATE_DESTROYED, $this->statuses()->state('uae'));

        $this->statuses()->clearDestroyed('uae');

        $this->assertNotSame(PeerStatusStore::STATE_DESTROYED, $this->detector()->evaluate('uae')['state']);
    }

    public function test_a_missed_heartbeat_never_destroys_a_node(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->statuses()->recordFailure('uae', 'timeout');
        }

        $decision = $this->detector()->evaluate('uae');

        $this->assertSame(PeerStatusStore::STATE_OFFLINE, $decision['state']);
        $this->assertSame('', (string) $this->statuses()->get('uae')['destroyed_at']);
    }

    public function test_heartbeats_record_the_reported_epoch_and_statistics(): void
    {
        $this->statuses()->recordHeartbeat('uae', 8.0, PeerStatusStore::STATE_ONLINE, [
            'epoch' => 'epoch-a',
            'started_at' => Timestamp::now(),
            'records' => 12,
            'events_committed' => 30,
            'storage_bytes' => 2048,
            'degraded' => false,
            'name' => 'UAE',
            'region' => 'middle-east',
        ]);

        $status = $this->statuses()->get('uae');

        $this->assertSame('epoch-a', $status['epoch']);
        $this->assertSame(12, $status['records']);
        $this->assertSame(30, $status['events_committed']);
        $this->assertSame(2048, $status['storage_bytes']);
        $this->assertSame('UAE', $status['name']);
    }
}
