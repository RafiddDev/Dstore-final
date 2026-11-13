# D-STORE — Architecture Document

**D-STORE** — Decentralized Distributed Storage and Synchronization System.
Four independent regional nodes (Pakistan, UAE, USA, UK) in a full-mesh peer-to-peer
network. Zero traditional database systems: every node persists data through a
custom PHP file-based storage engine and replicates mutations through an
append-only, cryptographically signed event log.

> Status: this document is the **Phase 1 deliverable** (architecture, protocol,
> storage format, replication strategy, consistency model, security model,
> environment setup) and is kept in step with the implementation as the
> phases land. **Phases 1–7 are complete** (see §15 and §16); the dashboard
> lands in Phase 8 and the distributed test suite in Phase 9. Anything not yet
> implemented is marked `(phase N)`.

---

## 1. System overview

```
                 ┌──────────────┐              ┌──────────────┐
                 │   PAKISTAN   │◄────────────►│     UAE      │
                 │  :8001  AP   │              │  :8002  AP   │
                 └──────┬───────┘              └──────┬───────┘
                        │  ▲                          │  ▲
            full mesh   │  │     full mesh             │  │
          (every node   │  │  (every node talks        │  │
         talks to every ▼  │   directly to every       ▼  │
           other node)  ┌──┴───────────┐          ┌───┴─────────┐
                        │     USA      │◄────────►│     UK      │
                        │  :8003  AP   │          │  :8004  AP  │
                        └──────────────┘          └─────────────┘
```

Hard invariants:

1. **No centralized database, no master node, no primary/secondary hierarchy.**
2. Every node is an independent Laravel application instance with its own
   identity, configuration, storage directory, event log, and sync engine.
3. Every mutation is a **signed event** appended to a durable local log, then
   replicated to peers. HTTP success ≠ synchronized: synchronization is only
   confirmed when the receiving peer acknowledges an *applied* event cursor.
4. A node keeps committing locally while partitioned. On reconnect it
   bidirectionally catches up without losing either side's writes.
5. All replicas converge to identical state because conflict resolution is a
   **deterministic function of the event set** (no timestamps used for
   ordering — clocks are only informational).

### Deployment topology

- **Development (this machine):** one codebase, four runtime instances
  (`php artisan --env=<node> serve --port=<port>`), each with its own
  `.env.<node>` and storage root under `nodes/<node>/storage`.
- **Production:** the same codebase cloned onto four geographically separated
  Linux servers; each server runs one node with its own `.env` and TLS.
  Node identity, peers, and ports are 100% environment-driven — no code
  changes are needed to move a node.

---

## 2. Node anatomy

Every node contains the same layered components (namespace `App\DStore\…`):

| Layer | Components | Responsibility |
|---|---|---|
| **Storage** | `Engine`, `RecordStore`, `EventLog`, `IndexStore`, `SnapshotManager`, `IntegrityGuard`, `FileLocker`, `TombstoneStore` | CRUD on record files, append-only event log, indexes, snapshots/backups, sha-256 integrity, flock-based locking, atomic writes, corruption detection/quarantine |
| **Security** | `KeyManager`, `MessageSigner`, `PeerAuthenticator`, `ReplayGuard`, `BootstrapToken`, `CanonicalJson` | Ed25519 key management, signed envelopes/events, peer authentication, replay protection, registration token |
| **Network** | `PeerClient`, `PeerRegistry`, `DiscoveryService`, `RegistrationService`, `NodeSelector` | Node-to-node HTTP calls, peer discovery, node registration/verification, client failover to online nodes |
| **Replication** | `EventReplicator`, `PendingQueue`, `DeliveryTracker`, `IdempotencyGuard`, `Backoff` | Fan-out of committed events, delivery acks, retries with exponential backoff, duplicate-message detection |
| **Synchronization** | `SyncEngine`, `PeerCursor`, `CatchUpService`, `GapDetector` | Incremental catch-up, per-peer checkpoints, missing-event (gap) detection |
| **ConflictResolution** | `VersionVector`, `LamportClock`, `ConflictResolver`, `Policies` | Version vectors, causality, deterministic conflict policies |
| **Recovery** | `RecoveryManager`, `SnapshotRestore`, `IndexRebuilder`, `IntegrityVerifier`, `BackupManager` | Node rejoin sequence, snapshot restore, index rebuild, integrity verification, backups |
| **NodeManagement** | `NodeIdentity`, `MembershipService`, `NodeStatusRegistry` | Node identity card, peer membership, status bookkeeping |
| **Monitoring** | `HeartbeatService`, `FailureDetector`, `StatsCollector`, `HealthService` | Heartbeats, failure detection with suspicion, live statistics for the dashboard |

Dependency injection everywhere: services are bound through
`DStoreServiceProvider` against interfaces; controllers and commands never
touch the filesystem directly — only through the storage abstraction layer.

---

## 3. Node identity

- `node_id` — short unique id (`pakistan`, `uae`, `usa`, `uk`), from
  `DSTORE_NODE_ID`.
- Each node generates an **Ed25519 keypair on first use** (PHP `sodium`).
  The secret key is stored at `metadata/keys/node.key`, encrypted with
  `xsalsa20poly1305` (`sodium_crypto_secretbox`) under a key derived from
  `APP_KEY` via HKDF-SHA256, and the file is chmod 0600 on Linux. Copying the
  storage directory to another machine is useless without `APP_KEY`.
- The secret key never leaves the node directory: it is never written to an
  API response, a log line, the dashboard, or any frontend code. Only
  `NodeIdentity::toArray()` — which carries the **public** key — is exposed.
- Identity card: `metadata/node.json`:

```json
{
  "node_id": "pakistan",
  "name": "Pakistan",
  "region": "asia-south",
  "url": "http://127.0.0.1:8001",
  "public_key": "<base64 ed25519 pubkey>",
  "fingerprint": "<sha256(pubkey) hex>",
  "created_at": "2026-11-12T10:00:00Z"
}
```

`epoch` increments on every restart — heartbeats carry it, so peers detect a
restart immediately (even with the same URL) and trigger a sync check.

### 3.1 Enrolment (bootstrap token → pinned key)

The bootstrap token is an **out-of-band** secret shared by the operator, not a
long-term credential:

1. The joining node generates its own keypair and calls
   `POST /api/dstore/v1/nodes/register` with its node id, URL, public key,
   a fresh nonce and the bootstrap token.
2. The receiving node verifies the token with `hash_equals`
   (`DSTORE_REGISTRATION_MODE=token`). A wrong or missing token is refused.
3. The receiving node then verifies the **proof of possession**: an Ed25519
   signature, made with the private key behind the offered public key, over
   the canonical form of `{node_id, public_key, url, nonce}`. A node that
   cannot sign with the key it offers is refused.
4. On success the receiving node **pins** the presented public key in
   `metadata/peers.json` and marks the peer `approved`.
5. The same exchange runs in the other direction, so a full mesh ends up with
   every node pinning every other node.

Once a peer is approved its **Ed25519 key is what authorises it** — the
bootstrap token is only needed for the enrolment door. A peer that is
configured in the environment but has no key pinned stays `pending` and
**cannot** send an accepted message.

### 3.2 Trust store

`metadata/peers.json` holds one record per peer:

```json
{
  "uae": {
    "id": "uae",
    "name": "UAE",
    "url": "http://127.0.0.1:8002",
    "public_key": "<base64>",
    "fingerprint": "<sha256 hex>",
    "status": "approved",
    "enrolled_at": "…",
    "approved_at": "…",
    "revoked_at": null
  }
}
```

`status` is one of `pending` → `approved` → `revoked`. Re-enrolling a peer
with a **different** public key is refused: the old key must be revoked
first, so a replayed registration request cannot silently swap a trusted key.

### 3.3 Signed events

Every event appended to the local log is signed with the node's secret key
over its canonical JSON (recursively key-sorted), excluding the signature
field itself. Peers verify the event signature against the pinned public key
before applying it, so a node cannot inject a forged event into the mesh.

---

## 4. Communication protocol (v1)

### 4.1 Transport and serialization

- HTTP/1.1 + JSON. Development: `http://127.0.0.1:<port>`. Production:
  HTTPS/TLS (see §10). All traffic is additionally protected at application
  level by Ed25519 signatures, so the protocol is safe even over a plaintext
  transport during development.
- Client timeouts: connect 5 s, request 15 s (configurable in
  `config/dstore.php`).

### 4.2 Message envelope

Every node-to-node request body is an envelope:

```json
{
  "header": {
    "message_id": "550e8400-e29b-41d4-a716-446655440000",
    "type": "record.created",
    "origin_node": "pakistan",
    "origin_clock": { "pakistan": 123, "uae": 9, "usa": 0, "uk": 0 },
    "sent_at": 1731412345,
    "nonce": "8f2c41ab…32 hex chars",
    "public_key": "<base64>",
    "signature": "<base64>"
  },
  "payload": { … }
}
```

- `message_id`: UUIDv4 — unique per message, used for duplicate detection.
- `signature`: Ed25519 over the **canonical JSON** of
  `{header without signature, payload}`.
- **Canonical JSON** = recursively sorted object keys,
  `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, no trailing whitespace.
  Identical content ⇒ identical signature input on every node.
- `nonce` + `sent_at` + a per-peer sliding window (`DSTORE_REPLAY_WINDOW`,
  default 300 s) provide replay protection: duplicated or too-old messages are
  rejected before processing.

### 4.3 Endpoint catalog

All under `/api/dstore/v1` (registered in `routes/api.php`):

| Method | Path | Purpose | Auth |
|---|---|---|---|
| `GET`  | `/health` | liveness: status, storage_ok, counts | public |
| `GET`  | `/nodes` | known peers + observed statuses | signed |
| `POST` | `/nodes/register` | node enrolment / identity exchange | bootstrap token + proof of possession |
| `POST` | `/heartbeat` | heartbeat + sync metadata (applied vector, pending count) | signed |
| `POST` | `/events/push` | deliver a batch of events; response contains the **applied vector** | signed |
| `GET`  | `/events?after=<vector>` | incremental pull of events after a vector (catch-up) | signed |
| `GET`  | `/sync/status` | per-peer applied/delivered vectors, pending counts, last sync | signed |
| `GET`  | `/snapshots` | list available snapshots | signed |
| `GET`  | `/snapshots/{name}` | download snapshot + hash manifest (recovery) | signed |
| `POST` | `/nodes/status` | gossip observed peer health | signed |
| `POST` | `/records/reconcile` | compare record versions for a set of ids (resolver/debug) | signed |

All eleven are implemented and live-verified.

The challenge that was originally planned as a separate endpoint is not
needed: the enrolment request carries its own fresh nonce and the signature
over it, so the challenge never has to travel on its own.

### 4.4 Peer authentication and registration

1. **Enrolment** (new or reinstalled node): the node sends
   `POST /nodes/register` carrying its identity card, the shared
   `DSTORE_BOOTSTRAP_TOKEN`, a fresh nonce and an Ed25519 **signature over
   that nonce and the offered public key** — proof that it really holds the
   private key behind the key it asks to be trusted with. With
   `DSTORE_REGISTRATION_MODE=token` (default) a valid token auto-accepts the
   node; `open` skips the token and is for local development only. The
   registrar replies with its own identity card, which the caller pins.
   A peer that is already approved cannot silently swap its key: the
   enrolment is refused until the peer is revoked.
2. **Ongoing authentication**: every signed request is verified against the
   sender's public key held in the peer registry. A node that is not in the
   trust store is rejected outright.
3. Replay protection: a ledger of recent `message_id`s plus a timestamp
   window. An event is processed **at most once** even if the message
   arrives many times.

### 4.5 Delivery semantics, retries, timeouts

- **Never assume an HTTP 200 means synchronized.** An event counts as
  *delivered to peer P* only when P's **applied vector** (confirmed in a push
  response or a later heartbeat) is ≥ the event's `(origin_node, seq)`.
- On failure: the event stays in the per-peer pending queue; retries use
  exponential backoff with jitter — base `DSTORE_RETRY_BASE_DELAY` (2 s),
  ×2 each attempt, capped at `DSTORE_RETRY_MAX_DELAY` (300 s), maximum
  `DSTORE_EVENT_MAX_ATTEMPTS` (10) attempts, after which the delivery is
  flagged **failed** (visible on the dashboard) — it is never silently
  dropped.
- Timeouts: connect 5 s / request 15 s; timeouts are failures, not successes.

---

## 5. Storage format (custom engine)

Storage root (per node): `nodes/<node>/storage/dstore/`
(configurable via `DSTORE_STORAGE_PATH`).

### 5.1 Directory tree

```
storage/dstore/
├── records/{collection}/{shard2}/{record_id}.json   # record documents
├── events/
│   ├── committed/{origin_node}/{seq08}.event        # append-only event log
│   ├── pending/{peer_id}/{origin}-{seq08}.event     # undelivered copies
│   └── sync/{peer_id}.json                          # per-peer checkpoints
├── indexes/
│   ├── {collection}/by-id.index                     # id → file,size,hash
│   ├── {collection}/by-updated.index                # scan order
│   └── events.applied                               # applied event ids (dedup)
├── snapshots/{collection}-{vector}-{ts}.snap(.manifest)
├── backups/{ts}/… + manifest.json
├── metadata/
│   ├── node.json                                    # identity card
│   ├── manifest.json                                # storage-wide checksums
│   ├── counters/{node}.seq                          # monotonic seq counters
│   ├── health.json                                  # persisted integrity issues
│   ├── tombstones/{record_id}.json                  # deletion markers
│   └── quarantine/                                  # corrupted files moved here
└── locks/                                           # flock files
```

`metadata/health.json` is the durable degraded-state marker. Every detected
integrity failure appends `{path, reason, detected_at}` to it, so a node stays
**degraded** across process restarts (CLI drill, HTTP request, worker) instead
of only inside the memory of the process that found the fault. `dstore:stats`
and the dashboard read this file; it is cleared only by an explicit repair.

- `{shard2}` = first 2 hex chars of `sha256(record_id)` — large collections
  are partitioned into 256 subdirectories instead of one huge directory.
- Records are **not** stored in one big JSON file; one file per record, plus
  indexes for scans.

### 5.2 Record file

```json
{
  "meta": {
    "record_id": "usr_9f2c41ab7d03e5",
    "collection": "users",
    "created_at": "2026-11-12T10:00:00Z",
    "updated_at": "2026-11-12T10:05:00Z",
    "deleted": false,
    "version": { "pakistan": 4, "uae": 0, "usa": 0, "uk": 1 },
    "history": [
      { "event_id": "pakistan-00000123-9f2c41ab", "version": { "pakistan": 4 }, "applied_at": "…" }
    ],
    "hash": "<sha256 hex>"
  },
  "data": { … }
}
```

- `hash` = sha256 of the canonical JSON of `{meta without hash, data}` —
  recomputed on every write, verified on every read.
- `version` is a **version vector** (map `node_id → counter`); it is the only
  ordering authority. `history` retains losing concurrent versions for audit
  and future merges.
- `deleted: true` + a tombstone file mark deletion; the physical file is
  retained for `DSTORE_TOMBSTONE_GC_DAYS` (7) then archived to backups.

### 5.3 Event file (append-only, immutable)

One file per event under `events/committed/{origin}/{seq08}.event`:

```json
{
  "event_id": "pakistan-00000123-9f2c41ab",
  "seq": 123,
  "origin_node": "pakistan",
  "type": "record.created",
  "collection": "users",
  "record_id": "usr_9f2c41ab7d03e5",
  "payload": { "meta": {…}, "data": {…} },
  "origin_clock": { "pakistan": 123, "uae": 9 },
  "created_at": "2026-11-12T10:05:00Z",
  "prev_event_id": "pakistan-00000122-3b7d01ce",
  "signature": "<base64>"
}
```

- `event_id` = `{origin}-{seq:08d}-{sha8}` where `sha8` = first 8 hex of the
  event content hash — globally unique, human-debuggable.
- `type` ∈ `record.created | record.updated | record.deleted | record.restored`.
- `payload` is a **full record snapshot** (v1 decision: records are small JSON
  documents; snapshots make conflict merging and idempotent re-application
  trivial. Patch-based payloads are a future optimization).
- `prev_event_id` hash-chains each node's log → tamper-evident history.
- `seq` comes from `metadata/counters/{node}.seq`, incremented under an
  exclusive `flock` with fsync — crash-safe monotonic ids, no duplicates.

### 5.4 Indexes

- `by-id.index`: JSON lines `{record_id} {relpath} {size} {sha256}` — loaded
  in memory per collection; rebuilt by scanning the records tree.
- `by-updated.index`: `{updated_at} {record_id} {version_sum}` sorted — powers
  scans and dashboards.
- `events.applied`: append-only list of applied event ids + an in-memory set —
  the idempotency gate (duplicate events never create duplicate records).

### 5.5 Snapshots and backups

- **Snapshot** (Phase 2): gzip JSON of a collection + the vector it was taken
  at + `.manifest` with sha-256 — used to bootstrap far-behind nodes.
- **Backup** (Phase 7): `dstore:backup` copies the full storage tree plus a
  hash manifest; `dstore:backup:verify` re-hashes every file;
  `dstore:backup:restore` verifies before overwriting. The `backups/`
  directory and `locks/` are excluded from the copy, so a backup never
  contains its predecessors. `DSTORE_BACKUP_RETENTION` prunes old backups.

### 5.6 Locking and atomicity

- All writes take an exclusive `flock` on a per-record (or per-collection)
  lock file in `locks/`; readers take a shared lock. PHP's `flock` works
  across processes — simultaneous file access is safe.
- Every write is atomic: write to `*.tmp.<rand>` → `fflush` → `fsync` →
  `rename()` over the target (atomic on NTFS and POSIX). A crash leaves
  either the old or the new file, never a half-written one.

### 5.7 Integrity and corruption detection

- Every record/event/snapshot file carries its sha-256; `metadata/manifest.json`
  lists file → hash for the whole tree.
- `dstore:verify` (Phase 2) re-hashes the tree; mismatches move the file to
  `metadata/quarantine/`, mark the node **degraded**, and raise a dashboard
  alert. Read paths verify hashes lazily as well.

---

## 6. Replication strategy

**Model: event-sourced replication with per-peer checkpoints.**

### 6.1 Local commit sequence (crash-safe)

1. Generate `event_id`, take `seq` from the counter under flock (+fsync).
2. Write event file atomically (tmp → fsync → rename), sign it.
3. Apply event to the record (atomic write, sha-256 in meta).
4. Update indexes; append `event_id` to `events.applied`.
5. Copy event into `events/pending/{peer}/` for every peer.
6. Background `EventReplicator` pushes batches (`DSTORE_PUSH_BATCH_SIZE`,
   default 100) to each peer.

A crash anywhere in 1–5 leaves the system recoverable: unapplied events are
replayed on boot; applied-but-undelivered events remain in the pending queue.

### 6.2 Push path

`POST /events/push` (batch) → peer verifies signatures, dedups, applies in
`seq` order per origin, responds `{ "applied": {origin: seq, …},
"duplicates": n }`. Sender advances that peer's checkpoint only by the
**applied vector** — not by the HTTP status.

### 6.3 Pull path (catch-up)

Heartbeats carry the applied vector both ways. If local `applied < peer's`:
`GET /events?after=<vector>` returns events in order → verify → dedup →
apply. The same comparison drives pushing our newer events to the peer, so
reconnect is always bidirectional.

### 6.4 Idempotency, checkpoints, gaps

- Idempotent apply: an event whose id is in `events.applied` is skipped
  (test 7 relies on this).
- Per-peer checkpoint file `events/sync/{peer}.json`:
  `{applied, delivered, pending_count, last_heartbeat_at, last_sync_at, state}`.
- Gap detection: applied vectors are maps `origin → seq`; a hole in any
  origin's sequence triggers a range request for exactly the missing events.

### 6.5 Partitions

While disconnected a node keeps committing locally; pending queues grow;
heartbeats fail; on reconnect the vector exchange finds the divergence and
both sides catch up. No writes are lost, no quorum is required.

---

## 7. Consistency model

**Eventual consistency (AP):** writes and reads succeed on any single node —
no quorum, no coordinator. All replicas converge because: (a) every node
eventually applies the same event set, (b) apply is idempotent, (c) conflict
resolution is a pure function of the event set.

### 7.1 Ordering: version vectors + event sequences

- Each record carries a version vector `V: node → counter`. A node bumps its
  own counter on every local mutation and merges (element-wise max) vectors
  it receives. Event sequences are the per-node Lamport component.
- Causality: `A → B` iff `V_A ≤ V_B` and `V_A ≠ V_B`.
  - Incoming event dominates local record → **accept**.
  - Local dominates incoming → **stale**, skip apply (event stays in the log;
    `history` is updated for audit).
  - Neither dominates → **concurrent** → conflict resolver.

### 7.2 Conflict resolution policies

Configured via `DSTORE_CONFLICT_POLICY`; deterministic on every node:

| Policy | Rule | Use |
|---|---|---|
| `vector-lww` (default) | Winner = higher `(sum of counters, node_id)` lexicographic; loser preserved in `meta.history` | general-purpose |
| `node-priority` | Static node precedence order from `DSTORE_NODE_PRIORITY`; falls back to `vector-lww` when neither side is ranked | regulatory/ownership requirements |
| `merge` | Field-level union of the two sides; a field both sides changed is decided by `vector-lww` | documents, profiles |

Timestamps are never used for ordering (clocks across servers are not
trusted); they are recorded for display only.

**Every node must run the same policy.** A node running `merge` while its
peers run `vector-lww` resolves the same pair of writes differently and the
mesh diverges permanently. The policy is therefore part of the node's
configuration, not a per-request option.

#### Implementation

| Class | Role |
|---|---|
| `VersionVectorLwwResolver` | default policy: version-vector sum, then the writing node id as the tiebreak |
| `NodePriorityResolver` | ranked node ids from config, LWW fallback |
| `MergeResolver` | field-level union, wrapping a fallback resolver |
| `ResolverFactory` | builds the resolver named by `DSTORE_CONFLICT_POLICY` |
| `ConflictLog` | append-only record of every resolved conflict (`metadata/conflicts.json`, bounded by `DSTORE_CONFLICT_LOG_LIMIT`) |
| `RecordComparer` | compares local records against a peer's claimed versions (`in-sync`, `local-newer`, `peer-newer`, `concurrent`, `missing-local`) |

The tiebreak is **total**: the writing node id is compared lexicographically,
so the greater node id wins a version-sum tie. Every node that holds the
same pair of concurrent writes therefore picks the same side, whatever the
arrival order.

#### Determinism of the resulting record

The merged record is byte-identical on every node, not merely equivalent:

- The **version** is the element-wise maximum of the two sides, so it does
  not depend on which side was local.
- The **history** entries are ordered by their canonical version JSON, not by
  arrival order. A policy that merges both sides records both replaced
  states; `vector-lww` records the losing state. Nothing written is ever
  dropped silently.
- The **history** is deduplicated by version, so an event that already carries
  a state does not record it a second time when it is applied.
- The **data** of a merged record is the field-level union of both sides,
  with a field both sides changed decided by the fallback resolver.

This is verified by `tests/Feature/ConflictResolution/ConflictDeterminismTest.php`,
which applies the same two concurrent writes in both orders and compares the
resulting records.

### 7.3 Which operations are safe independently

| Operation | Safe without coordination? | Mechanism |
|---|---|---|
| CREATE record | **Yes** | record id generated locally (prefix + 20 chars base62 from CSPRNG) — collision probability negligible; idempotent by event id |
| UPDATE record | **Yes** (conflict-detected) | version vector detects concurrent writes; resolver picks deterministically |
| DELETE record | **Yes** (tombstone) | delete is an event that bumps the vector; tombstone prevents resurrection by stale creates |
| READ record | **Yes** | local replica (+ optional read-repair later) |
| Unique field constraints across nodes (e.g. unique email) | **No** — eventually enforced only | documented limitation in v1; requires coordination/CRDT registers (future) |
| Multi-record transactions | **No** — unsupported in v1 | each event is atomic; cross-record atomicity is a future extension |

### 7.4 Convergence guarantee

Given the same set of applied events, every node computes the same record
state: apply order is fixed (per-origin seq order, origin order irrelevant
because resolution is associative-commutative on the final vector state and
the tiebreak is total). Network partitions therefore heal without operator
intervention.

---

## 8. Failure detection and node states

- `HeartbeatService` sends a signed heartbeat to every peer every
  `DSTORE_HEARTBEAT_INTERVAL` (5 s) carrying: epoch, applied vector, pending
  count, storage_ok, record counts.
- `FailureDetector` per peer:
  - response within `DSTORE_HEARTBEAT_TIMEOUT` (15 s) → **online**
  - timeout → **unreachable** (suspected; never immediately fatal)
  - `DSTORE_MISSES_BEFORE_OFFLINE` (3) consecutive misses → **offline**
  - corruption reported by integrity checks → **degraded** (node still serves)
  - **destroyed** is only ever set by an explicit admin action — the system
    never declares permanent destruction on its own.
- Restart detection: heartbeat epoch change → peer restarted → run sync check.
- If a node is offline: others keep operating; clients are steered to online
  nodes by `NodeSelector`; data remains available through surviving replicas.

**Implementation (Phase 7):**

- `HeartbeatService` posts a signed heartbeat and records the reply in
  `metadata/peer_status.json`: state, latency, consecutive failures, epoch,
  restarts, uptime, records, events and storage bytes. The receiver also
  records the sender, so one signed heartbeat updates both sides.
- `FailureDetector` is a pure decision function over that file; `refresh()`
  persists only real transitions. `dstore:monitor` runs a round every
  `DSTORE_HEARTBEAT_INTERVAL` and gossips the result to every reachable peer
  via the signed `/nodes/status` endpoint.
- Gossip is advisory: a peer this node has observed directly is never
  overridden by a third node's opinion. `destroyed` requires the explicit
  `markDestroyed()` administrative action and is never inferred.
- The runtime epoch lives in `metadata/runtime.json`. Only the long-running
  `dstore:monitor` and `dstore:sync:daemon` call `NodeRuntime::beginRun()`;
  HTTP workers never do, so serving a request never looks like a restart.

## 9. Recovery and node rejoin

Rejoin sequence (`RecoveryManager`, Phase 7) — exactly as specified:

1. **Authenticate** the returning node (signed envelope; a reinstalled node
   enrols first with the bootstrap token and a proof of possession).
2. **Discover** peers (`GET /nodes` from any seed in config).
3. **Exchange sync metadata** (applied vectors, epochs).
4. **Compare event histories** (vector diff, gap detection).
5. **Identify missing records/events**.
6. **Download missing events** (ordered pull).
7. **Verify hashes** (signatures + record sha-256).
8. **Restore snapshots** if the node is empty/fresh or further behind than
   the snapshot threshold.
9. **Rebuild indexes**.
10. **Resume normal sync**.

Recovery never overwrites newer data with older versions: restored events
still pass through the idempotency gate and the version-vector resolver, so
stale events are skipped exactly as in normal operation.

**Implementation (Phase 7):** `dstore:recover` runs the sequence as
`IdentityRegistry` check → `StorageEngine::verify()` (sha-256, corruption
quarantined) → optional `SnapshotRestorer` (restores only missing or older
records) → `EventReplayer` (normal `EventApplier`, own log trusted) →
`IndexRebuilder` → `SyncEngine` with every peer → `RecoveryCheckpoint`
(`recovery/checkpoint.json` plus `recovery/checkpoints.jsonl`). Steps 2-6 are
performed by `SyncEngine` (heartbeat, vector diff, ordered pull, signature
verification), and step 8 is opt-in through `--restore-snapshot`,
`--snapshot=<name>` or `DSTORE_AUTO_SNAPSHOT_RESTORE=true`.

---

## 10. Security model

| Concern | Mechanism | Status |
|---|---|---|
| Node identity | Ed25519 keypair per node, fingerprint in registry | done (Phase 3) |
| Key management | secret key encrypted at rest with `APP_KEY` (xsalsa20poly1305), file 0600, never in frontend/API output | done (Phase 3) |
| Message authenticity | Ed25519 signature over canonical JSON of every envelope | done (Phase 3) |
| Event authenticity | Every event signed at commit time; peers verify against the pinned key | done (Phase 3) |
| Registration | pre-shared `DSTORE_BOOTSTRAP_TOKEN` verified with `hash_equals`, then the public key is pinned | done (Phase 3) |
| Replay attacks | per-message id ledger + timestamp window, persisted in `metadata/replay.json` | done (Phase 3) |
| Unauthorized nodes | a peer must be `approved` with a pinned key; unknown or revoked nodes are refused | done (Phase 3) |
| Integrity | sha-256 on records, events, snapshots, backups + periodic verification | done (Phase 2) |
| Transport | HTTPS/TLS in production (reverse proxy); application-level signing is independent of transport | Phase 4 (deployment) |
| Backup safety | backup manifests carry hashes; restore verifies before applying | done (Phase 7) |

---

## 11. Configuration reference

Per-node env file `.env.<node>` (Phase 1 creates these; `config/dstore.php`
maps them, Phase 2):

| Variable | Example | Purpose |
|---|---|---|
| `DSTORE_NODE_ID` | `pakistan` | unique node id |
| `DSTORE_NODE_NAME` | `Pakistan` | display name |
| `DSTORE_REGION` | `asia-south` | region label |
| `DSTORE_PUBLIC_URL` | `http://127.0.0.1:8001` | URL peers use to reach this node |
| `DSTORE_PORT` | `8001` | dev server port |
| `DSTORE_STORAGE_PATH` | `nodes/pakistan/storage` | storage root (relative to app root or absolute) |
| `DSTORE_PEERS` | `uae,usa,uk` | comma-separated peer node ids |
| `DSTORE_PEER_UAE_URL` | `http://127.0.0.1:8002` | per-peer URL (one per peer) |
| `DSTORE_BOOTSTRAP_TOKEN` | `<hex64>` | shared registration secret (same on all nodes) |
| `DSTORE_REGISTRATION_MODE` | `token` | `token` \| `open` (open skips the token — dev only) |
| `DSTORE_REPLAY_WINDOW` | `300` | seconds, replay window |
| `DSTORE_MAX_CLOCK_SKEW` | `300` | seconds, tolerated clock skew on inbound messages |
| `DSTORE_REQUIRE_SIGNED_EVENTS` | `true` | reject events that arrive without a valid signature |
| `DSTORE_HTTP_TIMEOUT` | `10` | seconds, per-request timeout when talking to a peer |
| `DSTORE_HTTP_MAX_ATTEMPTS` | `3` | attempts per peer request |
| `DSTORE_HEARTBEAT_INTERVAL` | `5` | seconds |
| `DSTORE_HEARTBEAT_TIMEOUT` | `15` | seconds |
| `DSTORE_MISSES_BEFORE_OFFLINE` | `3` | consecutive missed heartbeats |
| `DSTORE_RETRY_BASE_DELAY` | `2` | seconds, retry backoff base |
| `DSTORE_RETRY_MAX_DELAY` | `300` | seconds, backoff cap |
| `DSTORE_EVENT_MAX_ATTEMPTS` | `10` | attempts before delivery marked failed |
| `DSTORE_CONFLICT_POLICY` | `vector-lww` | `vector-lww` \| `node-priority` \| `merge` (Phase 6) |
| `DSTORE_NODE_PRIORITY` | `pakistan,uae,usa,uk` | node precedence for the `node-priority` policy |
| `DSTORE_CONFLICT_LOG_LIMIT` | `200` | resolved conflicts kept in `metadata/conflicts.json` |
| `DSTORE_HISTORY_LIMIT` | `50` | versions kept per record |
| `DSTORE_TOMBSTONE_GC_DAYS` | `7` | tombstone retention (Phase 7) |
| `DSTORE_BACKUP_RETENTION` | `0` | backups kept by `dstore:backup` (`0` = keep all) |
| `DSTORE_GOSSIP` | `true` | share peer observations with reachable peers |
| `DSTORE_ALLOW_REKEY` | `true` | accept a key change after a valid re-enrolment |
| `DSTORE_AUTO_SNAPSHOT_RESTORE` | `false` | restore the newest snapshot during recovery |
| `DSTORE_BACKUP_BEFORE_RESTORE` | `false` | take a backup before a snapshot restore |
| `DSTORE_PUSH_BATCH_SIZE` | `100` | events per push batch |

---

## 12. Repository structure

```
dstore/
├── .env.pakistan  .env.uae  .env.usa  .env.uk      # per-node configuration
├── app/
│   ├── DStore/
│   │   ├── Storage/               # §2 — engine, records, event log, indexes,
│   │   │                          #   snapshots, integrity, locks, tombstones
│   │   ├── Security/              # §10 — keys, signer, auth, replay guard
│   │   ├── Network/               # §4 — peer client, registry, discovery, registration
│   │   ├── Replication/           # §6 — replicator, pending queue, delivery tracker
│   │   ├── Synchronization/       # §6 — sync engine, cursors, catch-up
│   │   ├── ConflictResolution/    # §7 — vectors, clocks, resolver, policies
│   │   ├── Recovery/              # §9 — recovery manager, restore, rebuild, backup
│   │   ├── NodeManagement/        # §3 — identity, membership, statuses
│   │   ├── Monitoring/            # §8 — heartbeats, failure detector, stats
│   │   ├── Dashboard/             # §13 — read model over the node's own state
│   │   ├── Support/               # canonical json, hashing, uuids, atomic file io
│   │   └── DStoreServiceProvider.php
│   └── Http/Controllers/DStore/   # API controllers (Phase 4) + dashboard (Phase 8)
├── config/dstore.php
├── routes/api.php                 # /api/dstore/v1/*
├── routes/web.php                 # dashboard (Phase 8)
├── nodes/
│   ├── pakistan/storage/  logs/
│   ├── uae/storage/       logs/
│   ├── usa/storage/       logs/
│   └── uk/storage/        logs/
├── tests/
│   ├── Unit/        Feature/      # per-component
│   ├── Integration/               # multi-component within a node
│   └── Distributed/               # multi-node tests 1–10 (Phase 9)
├── docs/ARCHITECTURE.md
└── README.md
```

---

## 13. Running the nodes (development)

Prerequisites (verified on this machine in Phase 1):

- PHP ≥ 8.2 with `sodium`, `openssl`, `curl`, `mbstring`, `json`, `fileinfo`,
  `ctype`, `tokenizer`, `xml` — using Laragon's PHP 8.5
  (`C:\laragon\bin\php\php-8.3\php.exe`; in Laragon switch
  *Menu → PHP → Version → 8.3* or prepend to PATH, see README).
- Composer ≥ 2.2 (using 2.11.1).
- No database server of any kind — this is the point of the project.

Run each node in its own terminal:

```bash
php artisan --env=pakistan serve --port=8001
php artisan --env=uae      serve --port=8002
php artisan --env=usa      serve --port=8003
php artisan --env=uk       serve --port=8004
```

`artisan serve` does not serve the request itself: it spawns a child PHP
process that re-selects its environment file from `APP_ENV`. Each
`.env.<node>` therefore sets `APP_ENV=<node>` (`APP_ENV=pakistan`, …).
Without it the child falls back to the base `.env` and all four nodes serve
as the same identity. This was observed and fixed during Phase 4
verification.

Quick liveness check:

```bash
curl http://127.0.0.1:8001/api/dstore/v1/health
```

Signed traffic requires an enrolled peer:

```bash
php artisan --env=pakistan dstore:node:enroll    # register with all peers
php artisan --env=pakistan dstore:node:discover  # probe all peers
```

### Dashboard (Phase 8)

Every node serves the same dashboard from its own storage; there is no central
UI server.

| Route | Purpose |
|---|---|
| `GET /` | Network overview: mesh health, node table, conflicts, backups, last recovery |
| `GET /nodes/{node}` | One node: identity, health, data, replication vectors, collections, queues |
| `GET /dashboard/data` | JSON read model for the overview (polled every 5 s) |
| `GET /dashboard/data/nodes/{node}` | JSON read model for one node |
| `POST /dashboard/probe/{node}` | Send one signed heartbeat to a peer (CSRF-protected) |

`DashboardService` composes the storage engine stats, `PeerStatusStore`
(heartbeat results), `FailureDetector` (detected state), `DeliveryTracker`
(sync cursors and pending counts), `ConflictLog`, `BackupManager` and
`RecoveryCheckpoint`. Nothing is hard-coded: a node that stops answering turns
`unreachable` then `offline` because the data behind the page says so.

The page is read-only except for **Probe now**, which sends the same signed
heartbeat the monitor sends, so an operator can refresh a peer's state without
waiting for the next interval. It exposes no secret material (no private keys,
no pinned public key). In production, protect the dashboard with your reverse
proxy / network policy — it has no authentication of its own.

To run it:

```bash
php artisan --env=pakistan serve --port=8001
# http://127.0.0.1:8001/
php artisan --env=pakistan dstore:monitor --once   # populate peer states
```

---

## 14. Debugging

- Per-node engine logs: `nodes/<node>/logs/node.log` (Phase 2+).
- Framework logs: `storage/logs/laravel.log`.
- `php artisan dstore:stats` — record/event/pending counts per node.
- `php artisan dstore:verify` — re-hash the storage tree, report corruption.
- `php artisan dstore:event:dump <event_id>` — inspect a replicated event.
- `php artisan dstore:sync:status` — per-peer cursors, applied vectors, pending
  counts and last sync time.
- `php artisan dstore:sync:run` — one bidirectional sync pass with every peer.
- `php artisan dstore:sync:daemon` — the same pass in a loop (`--once` for a
  single pass).
- `php artisan dstore:events:repair` — re-sign the events in this node's own
  log that were never signed, and release the events that are in quarantine.
  `--dry-run` reports without changing anything; an event whose content no
  longer matches its id is reported as tampered and left untouched.
- `php artisan dstore:records:restore` — rebuild the records the event log
  knows about but the record store lost. `--check` reports what is missing.
- `php artisan dstore:records:reconcile` — make the stored records agree with
  what the log proves (writer, duplicated history entries). `--check` reports
  without changing anything.
- `php artisan dstore:node:list` — this node's identity and every peer's
  trust status (`pending` / `approved` / `revoked`) plus fingerprints.
- `php artisan dstore:node:keygen` — create or rotate this node's identity.
- `php artisan dstore:node:approve <peer> --public-key=<b64>` — pin a peer's
  key by hand.
- `php artisan dstore:node:enroll` — enrol with every configured peer.
- `php artisan dstore:node:discover` — probe every peer and record the result.
- `php artisan dstore:node:revoke <peer>` — revoke a peer's trust.
- Dashboard (Phase 8) shows live pending/failed events and peer statuses —
  the first place to look for replication problems.
- `/dashboard/data` returns the same numbers as JSON, which is convenient for
  `curl | jq` and for the distributed test runner (Phase 9).

---

## 15. Phase roadmap

| Phase | Scope | Status |
|---|---|---|
| 1 | Architecture, protocol, storage format, env setup | **done** |
| 2 | Custom storage engine (records, event log, indexes, integrity, snapshots) | **done** |
| 3 | Security + node identity (keys, signing, auth, replay guard) | **done** |
| 4 | P2P network layer (endpoints, discovery, registration, timeouts/retries) | **done** |
| 5 | Event replication + synchronization (cursors, catch-up, idempotency) | **done** |
| 6 | Conflict resolution (version vectors, policies) | **done** |
| 7 | Failure detection, health monitoring, recovery, backups | **done** |
| 8 | Dashboard (live network data, node detail pages) | **done** |
| 9 | Automated tests 1–10 + end-to-end verification | **design complete** |

---

## 16. Phase verification checklists

### Phase 1 — environment

- [x] PHP 8.5.12 runs; Composer 2.11.1 runs on it (PATH prepended).
- [x] Required extensions present: sodium, openssl, curl, mbstring, json,
      fileinfo, ctype, tokenizer, xml.
- [x] Laravel application boots (`php artisan about`, `route:list`).
- [x] `--env=<node>` selects `.env.<node>` (verified working).
- [x] `.env.pakistan|uae|usa|uk` exist with unique APP_KEY / ports / storage paths.
- [x] `nodes/<node>/storage` directories exist; `dstore/` tree matches §12.
- [x] This document + README reviewed and signed off by the project owner.

### Phase 2 — storage engine

- [x] Record CRUD with one file per record, sharded by `sha256(record_id)`.
- [x] Atomic writes (tmp → fsync → rename) and `flock` locking.
- [x] Version history + tombstones.
- [x] Append-only event log with per-node sequences and `prev_event_id` chain.
- [x] Indexes with compaction; gzip snapshots with manifests.
- [x] SHA-256 integrity, corruption detection, quarantine.
- [x] Degraded state persisted in `metadata/health.json` across restarts.
- [x] Test suite green.

### Phase 3 — security

- [x] Ed25519 keypair generated per node, secret encrypted at rest with
      `APP_KEY` (xsalsa20poly1305), file 0600 on Linux.
- [x] Secret key never appears in the identity card, API output or UI.
- [x] Bootstrap-token enrolment pins the peer's public key; `hash_equals`
      comparison; wrong token refused.
- [x] Trust store with `pending` / `approved` / `revoked`; a pinned key cannot
      be silently swapped.
- [x] Every envelope signed and verified against the pinned peer key.
- [x] Replay protection: message-id ledger + timestamp window, persisted.
- [x] Every locally produced event is signed at commit time and the signature
      travels with the copy queued for each peer.
- [x] Middleware refuses unsigned, tampered, replayed and unauthorised
      requests (401 / 403 / 409).
- [x] Verified across four live nodes: each node verifies the other's event
      signature; a tampered payload is rejected.
- [x] Test suite green (111 tests).

### Phase 4 — P2P network layer

- [x] Ten endpoints under `/api/dstore/v1/`, all signed except `/health`
      and `/nodes/register`.
- [x] A signed endpoint reads its input from the **verified envelope**, never
      from the raw request body.
- [x] Peer client with timeouts, exponential backoff and a fresh envelope per
      attempt (so a retry is never mistaken for a replay).
- [x] Enrolment over HTTP: bootstrap token + proof of possession of the
      presented public key; the reply's key is pinned locally.
- [x] Durable inbox for received events; a duplicate event is acknowledged
      but stored once.
- [x] Peer status tracking (online / unreachable / offline / degraded).
- [x] All 12 ordered node-to-node pairs verified live over real HTTP with
      signed envelopes: every one answered HTTP 200.
- [x] Live negative checks: unsigned → 401, tampered payload → 401,
      replayed envelope → 409, unknown node → 403.
- [x] A signed event pushed from Pakistan was confirmed on disk in the UAE
      node's durable inbox.
- [x] Test suite green (150 tests).

### Phase 5 — event replication + synchronization

- [x] Every locally committed event is queued **per peer** in a durable
      pending queue; the queue holds a full copy of the event.
- [x] Delivery is confirmed only by the peer's **applied vector**, never by
      an HTTP status. A peer that answers 200 without advancing its applied
      vector leaves the event pending.
- [x] A push carries the queued events; the receiving node applies them
      inline and answers with its applied vector.
- [x] The applied vector reports the **contiguous prefix** per origin, so a
      hole can never be mistaken for progress.
- [x] A pulled event is written to the local committed log before it is
      applied, so a node never advertises an event it cannot serve, and it is
      relayed to the other peers (full mesh, no coordinator).
- [x] Applying an event is idempotent: the same event twice is a duplicate,
      a stale version never overwrites newer local data, a tombstone is never
      resurrected.
- [x] A forged event (bad signature) is neither applied nor logged.
- [x] Permanently refused events are quarantined and dropped from the queue
      so a single broken event cannot stall the stream behind it; the
      quarantine keeps the event for the operator and flags the node degraded.
- [x] `dstore:events:repair` re-signs unsigned local events whose content
      still hashes to their id, and refuses to touch a tampered one.
- [x] `dstore:records:restore` rebuilds records the store lost from the log
      without overwriting a record that is still present.
- [x] `dstore:records:reconcile` makes the store agree with the log
      (writer, duplicated history entries) without touching the data.
- [x] Verified live on four nodes: all four converged to `records: 9,
      events_applied: 10, status: healthy` with `pending=0` on every peer
      link, and a byte-for-byte comparison of all four record stores reported
      `IDENTICAL`.
- [x] Test suite green (209 passed, 1 skipped).

### Phase 6 — conflict resolution

- [x] Three policies behind one interface: `vector-lww` (default),
      `node-priority` and `merge`, selected by `DSTORE_CONFLICT_POLICY` and
      built by `ResolverFactory`.
- [x] The tiebreak is total: version-vector sum first, then the writing node
      id. Two nodes holding the same pair of concurrent writes always pick
      the same side.
- [x] `node-priority` uses a ranked node list and falls back to `vector-lww`
      when neither side is ranked.
- [x] `merge` unions the two sides field by field; a field both sides changed
      is decided by the fallback resolver. The merge is symmetric.
- [x] The merged version is the element-wise maximum of both sides, so a later
      write wins against it and the node cannot oscillate.
- [x] The losing state is never discarded: it stays in `meta.history`, and a
      merge records **both** replaced states. History entries are ordered by
      their canonical version JSON, so the order does not depend on which
      side arrived first, and deduplicated by version so an event that
      already carries a state does not record it twice.
- [x] Every resolved conflict is appended to `metadata/conflicts.json`
      (bounded by `DSTORE_CONFLICT_LOG_LIMIT`) with both versions, both
      writers, the winner and the reason.
- [x] `POST /records/reconcile` compares a set of records against a peer's
      claimed versions and reports `in-sync` / `local-newer` / `peer-newer` /
      `concurrent` / `missing-local` per record.
- [x] Timestamps are never used for ordering.
- [x] Verified live on four nodes: a simultaneous write of the same record
      on Pakistan and UAE under the `merge` policy produced the **same**
      record — identical hash, identical version `{pakistan: 1, uae: 1}`,
      identical writer, identical history — on all four nodes, and a
      byte-for-byte comparison of the four record stores reported `IDENTICAL`.
      The delete of that record propagated as an identical tombstone
      everywhere.
- [x] Verified live over real signed HTTP: UAE called `/records/reconcile`
      on every peer and each answered HTTP 200 with the correct version,
      writer, hash and relation.
- [x] Determinism proven by test: the same two concurrent writes applied in
      both orders leave the identical record
      (`tests/Feature/ConflictResolution/ConflictDeterminismTest.php`).
- [x] Test suite green (239 passed, 1 skipped).

### Phase 7 — failure detection, monitoring, recovery, backups

**7A — failure detection and health**

- [x] `HeartbeatService` sends a signed heartbeat to every peer and records the
      reply (state, latency, attempts, epoch, restarts, uptime, records,
      events, storage bytes) in `metadata/peer_status.json`.
- [x] The receiver records the sender from the same signed heartbeat, so one
      exchange updates both sides.
- [x] `FailureDetector` is a pure decision function: one miss →
      `unreachable`, `DSTORE_MISSES_BEFORE_OFFLINE` consecutive misses →
      `offline`, corruption reported → `degraded`.
- [x] `destroyed` is never inferred; only `markDestroyed()` (an explicit
      administrative action) sets it and it stays until cleared.
- [x] Restart detection by epoch change; the epoch lives in
      `metadata/runtime.json` and only `dstore:monitor` /
      `dstore:sync:daemon` call `NodeRuntime::beginRun()`.
- [x] `dstore:monitor [--once] [--interval=] [--no-gossip] [--json]` runs a
      monitoring round and gossips its observations.
- [x] Gossip is advisory: a directly observed peer is never overridden by a
      third node's report (`PeerStatusStore::recordGossip`).
- [x] `/health` and the signed `/heartbeat` expose epoch, uptime and health;
      `/nodes` reports the detected state, not the raw stored one.
- [x] Sync uses the same heartbeat path, so a synced node cannot look offline
      merely because nobody ran the monitor.
- [x] Scheduler entries: monitor + sync every minute, backup 02:10, tombstone
      GC 03:10 (`routes/console.php`).

**7B — recovery, backups and garbage collection**

- [x] `dstore:recover` runs identity → verify → optional snapshot →
      event replay → index rebuild → catch-up sync → checkpoint, with
      `--no-sync`, `--no-verify`, `--no-snapshot`, `--snapshot=`,
      `--restore-snapshot` and `--json`.
- [x] `EventVerifier` trusts this node's own committed log, so recovery can
      replay it after the record store or applied index is lost.
- [x] Replay uses the normal `EventApplier`, so a stale event can never
      overwrite a newer record; indexes are rebuilt from the record files.
- [x] `SnapshotRestorer` restores only missing records or records whose
      snapshot version strictly dominates the local one; a tampered snapshot
      is refused.
- [x] `RecoveryCheckpoint` writes `recovery/checkpoint.json` plus a bounded
      `recovery/checkpoints.jsonl` history; a failed run is recorded as
      `failed` and the exception is propagated.
- [x] `dstore:backup` copies the whole storage tree with a sha-256 manifest;
      `dstore:backup:list`, `dstore:backup:verify` and
      `dstore:backup:restore --force` (verify before overwrite) complete the
      cycle. `backups/` and `locks/` are excluded from the copy.
- [x] `DSTORE_BACKUP_RETENTION` prunes the oldest backups after each run.
- [x] `dstore:index:rebuild` rebuilds one collection or all of them.
- [x] `dstore:gc:tombstones [--days=] [--dry-run]` removes tombstones and the
      deleted record files they guard once past retention, then rebuilds the
      affected indexes.
- [x] A re-enrolment may rekey a peer only when `DSTORE_ALLOW_REKEY=true`;
      with the default-off behaviour `PeerRegistry::approve()` still refuses a
      silent key swap.
- [x] 50 new Phase 7 tests (failure detector, heartbeat, monitor, monitoring
      API, index rebuild, event replay, snapshot restore, backups, tombstone
      GC, recovery manager, console commands).
- [x] Verified live on four nodes: all four `/health` endpoints returned
      epoch/uptime; `dstore:monitor` reported all three peers `online`
      (3/3) and gossip was accepted by all three; `dstore:recover` on Pakistan
      replayed 18 events and rebuilt 12 records across 3 collections;
      `dstore:backup` + `dstore:backup:verify` passed (55 files, 28,252
      bytes).
- [x] Full suite green: 290 tests, 289 passed, 1 skipped, 817 assertions.

### Phase 8 — dashboard

- [x] `DashboardService` builds a read model from the node's own storage:
      engine stats, identity, runtime epoch, peer statuses, failure-detector
      state, per-peer sync cursors, conflicts, backups and recovery checkpoints.
- [x] `GET /` renders the network overview (mesh health, node table, conflicts,
      backups, last recovery) and `GET /nodes/{node}` renders one node —
      identity, health, data, applied/delivered vectors, collections, pending
      queues, storage issues and recovery.
- [x] `GET /dashboard/data` and `GET /dashboard/data/nodes/{node}` expose the
      same read model as JSON; the page polls them every 5 s without a build
      step (vanilla JS, no npm).
- [x] `POST /dashboard/probe/{node}` sends one signed heartbeat so an operator
      can refresh a peer immediately; CSRF-protected and refused for the local
      node.
- [x] Every figure comes from stored state; nothing is hard-coded. A peer that
      stops answering turns `unreachable` → `offline` because the detector says
      so, and `destroyed` is only shown when it was set administratively.
- [x] The dashboard exposes no secret material (no private key, no pinned
      public key).
- [x] 10 dashboard tests (render, JSON shape, detected failures, self/peer
      pages, 404s, probe success/refusal/404).
- [x] Verified live on four nodes: overview and both detail page types
      rendered, JSON endpoints matched the pages, probe button refreshed a
      peer's state, auto-refresh ran with no console errors; after
      `dstore:monitor --once` the overview showed `4/4 online` and mesh
      `healthy`.
- [x] Full suite green: 301 tests, 300 passed, 1 skipped, 885 assertions.

### Phase 9 — automated tests 1–10 + end-to-end verification

**Design:** Distributed test runner (`tests/Distributed/run.php`) with 10 scenarios running across 4 nodes (Pakistan/UAE/USA/UK) on ports 8001–8004.

| # | Scenario | Description |
|---|---|---|
| 1 | Health & identity | All `/health` endpoints respond; node IDs match expected values |
| 2 | Full-mesh signed communication | Each node creates an event; it replicates to all other nodes via signed envelopes |
| 3 | Create replication | Write a record from one node; verify it appears on all other nodes within 2 sync passes |
| 4 | Update convergence | Two concurrent updates to the same record resolve to the same winner on all nodes (vector-lww policy) |
| 5 | Concurrent-write determinism | Two nodes write concurrently; the deterministic resolver picks the same winner everywhere |
| 6 | Delete/tombstone propagation | Delete a record from one node; the tombstone replicates; GC runs after `DSTORE_TOMBSTONE_GC_DAYS` |
| 7 | Offline catch-up | Take one node offline, write to the other three, bring it back, verify it catches up via sync |
| 8 | Restart/recovery from log | Stop a node, restart it, verify it recovers its state from the event log and re-syncs |
| 9 | Failure detection offline/recovery | Miss heartbeats on one node; verify it goes offline; recover it and verify it comes back online |
| 10 | Unsigned request rejection | Send an unsigned POST to a signed endpoint; verify 401/403 is returned |

**Runner design:** `tests/Distributed/run.php` starts all 4 node servers from `.env.dist1-4`, enrols nodes, runs scenarios, and shuts down. Records pass/fail results. See `tests/Distributed/run.php` for the full implementation.

**Env files:** `.env.dist1` (Pakistan), `.env.dist2` (UAE), `.env.dist3` (USA), `.env.dist4` (UK) — each sets unique `APP_ENV`, `APP_URL`, `DSTORE_NODE_ID`, `DSTORE_PORT`, and peer URLs with shared `DSTORE_BOOTSTRAP_TOKEN`.

**Prerequisites:** Laravel project on F: with phases 1–8 fully implemented and verified (301 tests green). Laragon PHP 8.3 with sodium/openssl/curl/mbstring/json/fileinfo. ~100 GB free on F: for node storage directories.

**Status:** Design complete. Ready for execution when live servers are available.

---

## 16. Phase verification checklists
