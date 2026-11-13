<?php

namespace App\DStore\Storage;

final class StoragePaths
{
    public function __construct(private readonly string $root) {}

    public static function make(string $root, string $basePath): self
    {
        $normalized = str_replace('\\', '/', trim($root));

        if (! self::isAbsolute($normalized)) {
            $normalized = rtrim(str_replace('\\', '/', $basePath), '/').'/'.ltrim($normalized, '/');
        }

        return new self(rtrim($normalized, '/'));
    }

    public function root(): string
    {
        return $this->root;
    }

    public function records(string $collection): string
    {
        return $this->root.'/records/'.$collection;
    }

    public function shard(string $recordId): string
    {
        return substr(hash('sha256', $recordId), 0, 2);
    }

    public function record(string $collection, string $recordId): string
    {
        return $this->records($collection).'/'.$this->shard($recordId).'/'.$recordId.'.json';
    }

    public function events(string $origin): string
    {
        return $this->root.'/events/committed/'.$origin;
    }

    public function event(string $origin, int $seq): string
    {
        return $this->events($origin).'/'.sprintf('%08d', $seq).'.event';
    }

    public function pending(string $peer): string
    {
        return $this->root.'/events/pending/'.$peer;
    }

    public function pendingEvent(string $peer, string $origin, int $seq): string
    {
        return $this->pending($peer).'/'.$origin.'-'.sprintf('%08d', $seq).'.event';
    }

    public function syncCursor(string $peer): string
    {
        return $this->root.'/events/sync/'.$peer.'.json';
    }

    public function index(string $collection): string
    {
        return $this->root.'/indexes/'.$collection;
    }

    public function byIdIndex(string $collection): string
    {
        return $this->index($collection).'/by-id.index';
    }

    public function byUpdatedIndex(string $collection): string
    {
        return $this->index($collection).'/by-updated.index';
    }

    public function appliedIndex(): string
    {
        return $this->root.'/indexes/events.applied';
    }

    public function snapshots(): string
    {
        return $this->root.'/snapshots';
    }

    public function backups(): string
    {
        return $this->root.'/backups';
    }

    public function backupDirectory(string $name): string
    {
        return $this->backups().'/'.basename($name);
    }

    public function backupTree(string $name): string
    {
        return $this->backupDirectory($name).'/tree';
    }

    public function backupManifest(string $name): string
    {
        return $this->backupDirectory($name).'/manifest.json';
    }

    public function metadata(): string
    {
        return $this->root.'/metadata';
    }

    public function nodeMeta(): string
    {
        return $this->metadata().'/node.json';
    }

    public function manifest(): string
    {
        return $this->metadata().'/manifest.json';
    }

    public function healthFile(): string
    {
        return $this->metadata().'/health.json';
    }

    public function runtimeFile(): string
    {
        return $this->metadata().'/runtime.json';
    }

    public function recovery(): string
    {
        return $this->root.'/recovery';
    }

    public function recoveryCheckpoint(): string
    {
        return $this->recovery().'/checkpoint.json';
    }

    public function recoveryHistory(): string
    {
        return $this->recovery().'/checkpoints.jsonl';
    }

    public function conflictLog(): string
    {
        return $this->metadata().'/conflicts.json';
    }

    public function secretKeyFile(): string
    {
        return $this->metadata().'/keys/node.key';
    }

    public function peerRegistryFile(): string
    {
        return $this->metadata().'/peers.json';
    }

    public function replayFile(): string
    {
        return $this->metadata().'/replay.json';
    }

    public function peerStatusFile(): string
    {
        return $this->metadata().'/peer_status.json';
    }

    public function inbox(string $origin): string
    {
        return $this->root().'/events/inbox/'.$origin;
    }

    public function inboxEvent(string $origin, int $seq): string
    {
        return $this->inbox($origin).'/'.sprintf('%08d', $seq).'.event';
    }

    public function inboxIndex(): string
    {
        return $this->root().'/indexes/events.inbox';
    }

    public function counter(string $nodeId): string
    {
        return $this->metadata().'/counters/'.$nodeId.'.seq';
    }

    public function tombstones(): string
    {
        return $this->metadata().'/tombstones';
    }

    public function tombstone(string $recordId): string
    {
        return $this->tombstones().'/'.$recordId.'.json';
    }

    public function quarantine(): string
    {
        return $this->metadata().'/quarantine';
    }

    public function quarantinedEvents(): string
    {
        return $this->quarantine().'/events';
    }

    public function locks(): string
    {
        return $this->root.'/locks';
    }

    public function lock(string $key): string
    {
        return $this->locks().'/'.sha1($key).'.lock';
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || (bool) preg_match('/^[A-Za-z]:\//', $path)
            || str_starts_with($path, '\\\\');
    }
}
