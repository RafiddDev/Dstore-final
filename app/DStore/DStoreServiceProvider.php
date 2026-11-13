<?php

namespace App\DStore;

use App\DStore\ConflictResolution\ConflictLog;
use App\DStore\ConflictResolution\ConflictResolver;
use App\DStore\ConflictResolution\RecordComparer;
use App\DStore\ConflictResolution\ResolverFactory;
use App\DStore\Monitoring\FailureDetector;
use App\DStore\Monitoring\HeartbeatService;
use App\DStore\Monitoring\MonitorService;
use App\DStore\Monitoring\NodeRuntime;
use App\DStore\Monitoring\PeerStatusStore;
use App\DStore\Network\PeerClient;
use App\DStore\Network\PeerDirectory;
use App\DStore\Network\PeerDiscoveryService;
use App\DStore\Network\RegistrationService;
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
use App\DStore\Storage\Contracts\StorageEngineInterface;
use App\DStore\Storage\Event;
use App\DStore\Storage\EventInbox;
use App\DStore\Storage\EventLog;
use App\DStore\Storage\EventQuarantine;
use App\DStore\Storage\FileLocker;
use App\DStore\Storage\HealthState;
use App\DStore\Storage\IndexStore;
use App\DStore\Storage\IntegrityGuard;
use App\DStore\Storage\RecordStore;
use App\DStore\Storage\RecordRestorer;
use App\DStore\Storage\SnapshotStore;
use App\DStore\Storage\StorageEngine;
use App\DStore\Storage\StoragePaths;
use App\DStore\Storage\TombstoneStore;
use App\DStore\Support\IdGenerator;
use App\DStore\Synchronization\DeliveryTracker;
use App\DStore\Synchronization\EventApplier;
use App\DStore\Synchronization\SyncEngine;
use Illuminate\Support\ServiceProvider;

final class DStoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/dstore.php', 'dstore');

        $this->app->singleton(StoragePaths::class, function ($app) {
            return StoragePaths::make(
                (string) $app['config']->get('dstore.storage.path'),
                $app->basePath(),
            );
        });

        $this->app->singleton(HealthState::class);
        $this->app->singleton(AtomicFile::class);
        $this->app->singleton(FileLocker::class);
        $this->app->singleton(IntegrityGuard::class);
        $this->app->singleton(RecordStore::class);
        $this->app->singleton(EventLog::class);
        $this->app->singleton(IndexStore::class);
        $this->app->singleton(SnapshotStore::class);
        $this->app->singleton(EventInbox::class);
        $this->app->singleton(PeerStatusStore::class);
        $this->app->singleton(NodeRuntime::class);

        $this->app->singleton(IdGenerator::class, function ($app) {
            return new IdGenerator((array) $app['config']->get('dstore.storage.collections', []));
        });

        $this->app->singleton(KeyManager::class, function ($app) {
            return new KeyManager(
                $app->make(StoragePaths::class),
                (string) $app['config']->get('app.key'),
            );
        });

        $this->app->singleton(MessageSigner::class, function ($app) {
            return new MessageSigner($app->make(KeyManager::class));
        });

        $this->app->singleton(EventSigner::class, function ($app) {
            return new EventSigner($app->make(MessageSigner::class));
        });

        $this->app->singleton(IdentityRegistry::class, function ($app) {
            $config = $app['config'];

            return new IdentityRegistry(
                $app->make(StoragePaths::class),
                $app->make(KeyManager::class),
                (string) $config->get('dstore.node.id'),
                (string) $config->get('dstore.node.name'),
                (string) $config->get('dstore.node.region'),
                (string) $config->get('dstore.node.public_url'),
            );
        });

        $this->app->singleton(PeerRegistry::class, function ($app) {
            return new PeerRegistry(
                $app->make(StoragePaths::class),
                (array) $app['config']->get('dstore.peers', []),
                (bool) $app['config']->get('dstore.security.allow_rekey', false),
            );
        });

        $this->app->singleton(BootstrapToken::class, function ($app) {
            $config = $app['config'];

            return new BootstrapToken(
                $config->get('dstore.security.bootstrap_token'),
                (string) $config->get('dstore.security.registration_mode', BootstrapToken::MODE_TOKEN),
            );
        });

        $this->app->singleton(ReplayGuard::class, function ($app) {
            $config = $app['config'];

            return new ReplayGuard(
                $app->make(StoragePaths::class),
                (int) $config->get('dstore.security.replay_window', 300),
                (int) $config->get('dstore.security.max_clock_skew', 300),
            );
        });

        $this->app->singleton(MessageAuthenticator::class, function ($app) {
            return new MessageAuthenticator(
                $app->make(MessageSigner::class),
                $app->make(PeerRegistry::class),
                $app->make(ReplayGuard::class),
                $app->make(IdentityRegistry::class),
            );
        });

        $this->app->singleton(NodeRegistrar::class, function ($app) {
            return new NodeRegistrar(
                $app->make(BootstrapToken::class),
                $app->make(PeerRegistry::class),
                $app->make(IdentityRegistry::class),
                $app->make(MessageSigner::class),
            );
        });

        $this->app->singleton(EventVerifier::class, function ($app) {
            $config = $app['config'];

            return new EventVerifier(
                $app->make(PeerRegistry::class),
                $app->make(MessageSigner::class),
                $app->make(IdentityRegistry::class),
                (string) $config->get('dstore.node.id'),
            );
        });

        $this->app->singleton(PeerDirectory::class, function ($app) {
            return new PeerDirectory(
                $app->make(PeerRegistry::class),
                (array) $app['config']->get('dstore.peers', []),
            );
        });

        $this->app->singleton(PeerClient::class, function ($app) {
            $config = $app['config'];

            return new PeerClient(
                $app->make(MessageAuthenticator::class),
                (int) $config->get('dstore.network.timeout', 10),
                (int) $config->get('dstore.network.max_attempts', 3),
                (int) $config->get('dstore.replication.retry_base_delay', 2),
                (int) $config->get('dstore.replication.retry_max_delay', 60),
            );
        });

        $this->app->singleton(PeerDiscoveryService::class, function ($app) {
            return new PeerDiscoveryService(
                $app->make(PeerDirectory::class),
                $app->make(PeerClient::class),
                $app->make(PeerStatusStore::class),
            );
        });

        $this->app->singleton(FailureDetector::class, function ($app) {
            $config = $app['config'];

            return new FailureDetector(
                $app->make(PeerStatusStore::class),
                (int) $config->get('dstore.monitoring.heartbeat_timeout', 15),
                (int) $config->get('dstore.monitoring.misses_before_offline', 3),
            );
        });

        $this->app->singleton(HeartbeatService::class, function ($app) {
            return new HeartbeatService(
                $app->make(PeerClient::class),
                $app->make(PeerDirectory::class),
                $app->make(PeerStatusStore::class),
                $app->make(FailureDetector::class),
                $app->make(NodeRuntime::class),
                $app->make(StorageEngineInterface::class),
                $app->make(EventLog::class),
                $app->make(IdentityRegistry::class),
            );
        });

        $this->app->singleton(MonitorService::class, function ($app) {
            return new MonitorService(
                $app->make(HeartbeatService::class),
                $app->make(PeerDirectory::class),
                $app->make(FailureDetector::class),
                $app->make(PeerStatusStore::class),
                $app->make(PeerClient::class),
                $app->make(NodeRuntime::class),
                $app->make(StorageEngineInterface::class),
                $app->make(IdentityRegistry::class),
            );
        });

        $this->app->singleton(RegistrationService::class, function ($app) {
            return new RegistrationService(
                $app->make(PeerClient::class),
                $app->make(PeerDirectory::class),
                $app->make(IdentityRegistry::class),
                $app->make(PeerRegistry::class),
                $app->make(BootstrapToken::class),
                $app->make(MessageSigner::class),
            );
        });

        $this->app->singleton(TombstoneStore::class);

        $this->app->singleton(EventQuarantine::class, function ($app) {
            return new EventQuarantine(
                $app->make(StoragePaths::class),
                $app->make(HealthState::class),
            );
        });

        $this->app->singleton(RecordRestorer::class, function ($app) {
            return new RecordRestorer(
                $app->make(RecordStore::class),
                $app->make(IndexStore::class),
                $app->make(EventLog::class),
                $app->make(TombstoneStore::class),
            );
        });

        $this->app->singleton(ConflictResolver::class, function ($app) {
            $config = $app['config'];

            return ResolverFactory::make(
                (string) $config->get('dstore.consistency.policy', 'vector-lww'),
                (array) $config->get('dstore.consistency.node_priority', []),
            );
        });

        $this->app->singleton(ConflictLog::class, function ($app) {
            return new ConflictLog($app->make(StoragePaths::class));
        });

        $this->app->singleton(RecordComparer::class, function ($app) {
            return new RecordComparer($app->make(RecordStore::class));
        });

        $this->app->singleton(EventApplier::class, function ($app) {
            return new EventApplier(
                $app->make(RecordStore::class),
                $app->make(EventLog::class),
                $app->make(IndexStore::class),
                $app->make(TombstoneStore::class),
                $app->make(EventVerifier::class),
                $app->make(ConflictResolver::class),
                $app->make(FileLocker::class),
                $app->make(ConflictLog::class),
            );
        });

        $this->app->singleton(DeliveryTracker::class, function ($app) {
            return new DeliveryTracker($app->make(EventLog::class));
        });

        $this->app->singleton(EventReplicator::class, function ($app) {
            $config = $app['config'];

            return new EventReplicator(
                $app->make(PeerClient::class),
                $app->make(PeerDirectory::class),
                $app->make(EventLog::class),
                $app->make(DeliveryTracker::class),
                $app->make(EventQuarantine::class),
                (string) $config->get('dstore.node.id'),
                (int) $config->get('dstore.replication.push_batch_size', 100),
                (int) $config->get('dstore.replication.event_max_attempts', 10),
            );
        });

        $this->app->singleton(EventRelay::class, function ($app) {
            return new EventRelay(
                $app->make(EventLog::class),
                $app->make(PeerDirectory::class),
            );
        });

        $this->app->singleton(SyncEngine::class, function ($app) {
            return new SyncEngine(
                $app->make(PeerClient::class),
                $app->make(PeerDirectory::class),
                $app->make(EventLog::class),
                $app->make(EventApplier::class),
                $app->make(EventReplicator::class),
                $app->make(DeliveryTracker::class),
                $app->make(EventVerifier::class),
                $app->make(EventRelay::class),
                $app->make(HeartbeatService::class),
            );
        });

        $this->app->singleton(StorageEngine::class, function ($app) {
            $config = $app['config'];

            return new StorageEngine(
                $app->make(StoragePaths::class),
                $app->make(RecordStore::class),
                $app->make(EventLog::class),
                $app->make(IndexStore::class),
                $app->make(SnapshotStore::class),
                $app->make(IdGenerator::class),
                $app->make(FileLocker::class),
                $app->make(IntegrityGuard::class),
                $app->make(HealthState::class),
                $app->make(TombstoneStore::class),
                $app->make(EventSigner::class),
                $app->make(ConflictLog::class),
                (string) $config->get('dstore.node.id'),
                array_keys((array) $config->get('dstore.peers', [])),
            );
        });

        $this->app->alias(StorageEngine::class, StorageEngineInterface::class);

        $this->app->singleton(IndexRebuilder::class, function ($app) {
            return new IndexRebuilder(
                $app->make(StoragePaths::class),
                $app->make(RecordStore::class),
                $app->make(IndexStore::class),
            );
        });

        $this->app->singleton(SnapshotRestorer::class, function ($app) {
            return new SnapshotRestorer(
                $app->make(SnapshotStore::class),
                $app->make(RecordStore::class),
                $app->make(IndexStore::class),
                $app->make(TombstoneStore::class),
            );
        });

        $this->app->singleton(EventReplayer::class, function ($app) {
            return new EventReplayer(
                $app->make(EventLog::class),
                $app->make(EventApplier::class),
                $app->make(IndexRebuilder::class),
            );
        });

        $this->app->singleton(BackupManager::class, function ($app) {
            return new BackupManager($app->make(StoragePaths::class));
        });

        $this->app->singleton(RecoveryCheckpoint::class, function ($app) {
            return new RecoveryCheckpoint($app->make(StoragePaths::class));
        });

        $this->app->singleton(TombstoneGarbageCollector::class, function ($app) {
            return new TombstoneGarbageCollector(
                $app->make(StoragePaths::class),
                $app->make(RecordStore::class),
                $app->make(IndexRebuilder::class),
            );
        });

        $this->app->singleton(RecoveryManager::class, function ($app) {
            $config = $app['config'];

            return new RecoveryManager(
                $app->make(IdentityRegistry::class),
                $app->make(StorageEngineInterface::class),
                $app->make(SnapshotStore::class),
                $app->make(SnapshotRestorer::class),
                $app->make(EventReplayer::class),
                $app->make(IndexRebuilder::class),
                $app->make(BackupManager::class),
                $app->make(RecoveryCheckpoint::class),
                $app->make(SyncEngine::class),
                $app->make(PeerDirectory::class),
                (bool) $config->get('dstore.recovery.auto_snapshot_restore', false),
                (bool) $config->get('dstore.recovery.backup_before_restore', false),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/dstore.php' => config_path('dstore.php'),
        ], 'dstore-config');
    }
}
