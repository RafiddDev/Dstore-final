<?php

namespace Tests;

use App\DStore\ConflictResolution\ConflictLog;
use App\DStore\ConflictResolution\ConflictResolver;
use App\DStore\ConflictResolution\RecordComparer;
use App\DStore\Network\PeerClient;
use App\DStore\Network\PeerDirectory;
use App\DStore\Network\PeerDiscoveryService;
use App\DStore\Network\RegistrationService;
use App\DStore\Monitoring\FailureDetector;
use App\DStore\Monitoring\HeartbeatService;
use App\DStore\Monitoring\MonitorService;
use App\DStore\Monitoring\NodeRuntime;
use App\DStore\Monitoring\PeerStatusStore;
use App\DStore\Recovery\BackupManager;
use App\DStore\Recovery\EventReplayer;
use App\DStore\Recovery\IndexRebuilder;
use App\DStore\Recovery\RecoveryCheckpoint;
use App\DStore\Recovery\RecoveryManager;
use App\DStore\Recovery\SnapshotRestorer;
use App\DStore\Recovery\TombstoneGarbageCollector;
use App\DStore\Replication\EventRelay;
use App\DStore\Replication\EventReplicator;
use App\DStore\Security\BootstrapToken;
use App\DStore\Security\EventSigner;
use App\DStore\Security\EventVerifier;
use App\DStore\Security\IdentityRegistry;
use App\DStore\Security\KeyManager;
use App\DStore\Security\MessageAuthenticator;
use App\DStore\Security\MessageSigner;
use App\DStore\Security\NodeRegistrar;
use App\DStore\Security\PeerRegistry;
use App\DStore\Security\ReplayGuard;
use App\DStore\Storage\AtomicFile;
use App\DStore\Storage\EventInbox;
use App\DStore\Storage\EventLog;
use App\DStore\Storage\EventQuarantine;
use App\DStore\Storage\FileLocker;
use App\DStore\Storage\HealthState;
use App\DStore\Storage\IndexStore;
use App\DStore\Storage\IntegrityGuard;
use App\DStore\Storage\RecordRestorer;
use App\DStore\Storage\RecordStore;
use App\DStore\Storage\SnapshotStore;
use App\DStore\Storage\StorageEngine;
use App\DStore\Storage\StoragePaths;
use App\DStore\Storage\TombstoneStore;
use App\DStore\Support\IdGenerator;
use App\DStore\Synchronization\DeliveryTracker;
use App\DStore\Synchronization\EventApplier;
use App\DStore\Synchronization\SyncEngine;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    protected string $dstoreStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dstoreStorage = storage_path('framework/testing/dstore/'.bin2hex(random_bytes(6)));

        config([
            'dstore.storage.path' => $this->dstoreStorage,
            'dstore.node.id' => 'test-node',
            'dstore.peers' => [],
        ]);

        $this->refreshDStoreBindings();
    }

    protected function tearDown(): void
    {
        if (isset($this->dstoreStorage) && is_dir($this->dstoreStorage)) {
            File::deleteDirectory($this->dstoreStorage);
        }

        parent::tearDown();
    }

    protected function refreshDStoreBindings(): void
    {
        foreach ([
            StoragePaths::class,
            HealthState::class,
            AtomicFile::class,
            FileLocker::class,
            IntegrityGuard::class,
            RecordStore::class,
            EventLog::class,
            IndexStore::class,
            SnapshotStore::class,
            EventInbox::class,
            PeerStatusStore::class,
            NodeRuntime::class,
            FailureDetector::class,
            HeartbeatService::class,
            MonitorService::class,
            IdGenerator::class,
            KeyManager::class,
            MessageSigner::class,
            EventSigner::class,
            IdentityRegistry::class,
            PeerRegistry::class,
            BootstrapToken::class,
            ReplayGuard::class,
            MessageAuthenticator::class,
            NodeRegistrar::class,
            EventVerifier::class,
            PeerDirectory::class,
            PeerClient::class,
            PeerDiscoveryService::class,
            RegistrationService::class,
            StorageEngine::class,
            TombstoneStore::class,
            EventQuarantine::class,
            RecordRestorer::class,
            ConflictResolver::class,
            ConflictLog::class,
            RecordComparer::class,
            EventApplier::class,
            DeliveryTracker::class,
            EventReplicator::class,
            EventRelay::class,
            SyncEngine::class,
            IndexRebuilder::class,
            SnapshotRestorer::class,
            EventReplayer::class,
            BackupManager::class,
            RecoveryCheckpoint::class,
            TombstoneGarbageCollector::class,
            RecoveryManager::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    protected function storagePath(string $suffix = ''): string
    {
        return $this->dstoreStorage.($suffix === '' ? '' : '/'.ltrim($suffix, '/'));
    }
}
