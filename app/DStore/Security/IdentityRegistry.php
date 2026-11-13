<?php

namespace App\DStore\Security;

use App\DStore\Storage\AtomicFile;
use App\DStore\Storage\StoragePaths;
use App\DStore\Support\CanonicalJson;
use App\DStore\Support\Timestamp;

/**
 * Persists this node's public identity card at metadata/node.json.
 */
final class IdentityRegistry
{
    public function __construct(
        private readonly StoragePaths $paths,
        private readonly KeyManager $keys,
        private readonly string $nodeId,
        private readonly string $nodeName,
        private readonly string $region,
        private readonly string $publicUrl,
    ) {}

    public function exists(): bool
    {
        return is_file($this->paths->nodeMeta()) && $this->keys->exists();
    }

    public function identity(): NodeIdentity
    {
        if (! is_file($this->paths->nodeMeta())) {
            return $this->create();
        }

        $decoded = json_decode((string) file_get_contents($this->paths->nodeMeta()), true);

        if (! is_array($decoded)) {
            throw new \App\DStore\Security\Exceptions\IdentityException(
                'Node identity file is not readable: '.$this->paths->nodeMeta()
            );
        }

        return NodeIdentity::fromArray($decoded);
    }

    public function publicIdentity(): array
    {
        return $this->identity()->toArray();
    }

    /**
     * Creates the identity card and the underlying key pair if they are missing.
     */
    public function create(): NodeIdentity
    {
        if (! $this->keys->exists()) {
            $pair = $this->keys->generate();
            $this->keys->store($pair['public'], $pair['secret']);
        }

        $identity = new NodeIdentity(
            $this->nodeId,
            $this->nodeName,
            $this->region,
            $this->keys->publicKey(),
            Timestamp::now(),
            $this->publicUrl,
        );

        $this->persist($identity);

        return $identity;
    }

    /**
     * Regenerates the key pair. Every peer must re-enrol afterwards because
     * the pinned public key changes.
     */
    public function rotate(): NodeIdentity
    {
        $this->keys->rotate();

        $identity = new NodeIdentity(
            $this->nodeId,
            $this->nodeName,
            $this->region,
            $this->keys->publicKey(),
            Timestamp::now(),
            $this->publicUrl,
        );

        $this->persist($identity);

        return $identity;
    }

    public function refreshUrl(): NodeIdentity
    {
        $identity = $this->identity();

        if ($identity->url === $this->publicUrl) {
            return $identity;
        }

        $updated = new NodeIdentity(
            $identity->nodeId,
            $identity->name,
            $identity->region,
            $identity->publicKey,
            $identity->createdAt,
            $this->publicUrl,
        );

        $this->persist($updated);

        return $updated;
    }

    private function persist(NodeIdentity $identity): void
    {
        AtomicFile::write(
            $this->paths->nodeMeta(),
            CanonicalJson::encode($identity->toArray()),
        );
    }
}
