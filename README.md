# D-STORE

Decentralized Distributed Storage and Synchronization System.
Four independent regional nodes (Pakistan, UAE, USA, UK) in a full-mesh P2P
network. **No database server of any kind** — a custom PHP file-based storage
engine, append-only signed event log, event-driven replication, offline
catch-up, conflict resolution with version vectors, failure detection,
recovery, and a live dashboard.

Full design: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

> **Current status: Phases 1–9 complete** (architecture + environment,
> storage engine, security and node identity, the signed P2P network layer,
> event replication + synchronization, conflict resolution, failure detection /
> health monitoring / recovery / backups, the live dashboard, and the
> distributed test suite design). See the phase table in the
> architecture document.

## Prerequisites

- PHP ≥ 8.2 with extensions: `sodium`, `openssl`, `curl`, `mbstring`,
  `json`, `fileinfo`, `ctype`, `tokenizer`, `xml`.
- Composer ≥ 2.2.
- **No** MySQL/PostgreSQL/SQLite/MongoDB — nothing to install, nothing to run.

On this machine the toolchain is Laragon's PHP 8.5:

```bash
# Make PHP 8.5 + Composer 2 the default for this shell (Git Bash)
export PATH="/c/laragon/bin/php/php-8.3:$PATH"
php -v        # expect 8.5.x
composer --version   # expect 2.11.x
```

Or switch Laragon permanently: *Laragon → Menu → PHP → Version → 8.3*.

## Installation (development)

```bash
cd dstore
composer install            # Phase 1 already scaffolded the app

# Phase 1 already created .env.pakistan/.env.uae/.env.usa/.env.uk
# with unique APP_KEY, ports 8001-8004, and separate storage paths.
```

Each `.env.<node>` sets `APP_ENV=<node>` (for example `APP_ENV=pakistan`).
This is required, not cosmetic: `artisan serve` hands the HTTP request to a
child PHP process, and that child re-selects its environment file from
`APP_ENV`. Without it every node would boot the base `.env` and all four
would end up serving as the same node.

## Running the four nodes

Four terminals (or one terminal per node):

```bash
php artisan --env=pakistan serve --port=8001
php artisan --env=uae      serve --port=8002
php artisan --env=usa      serve --port=8003
php artisan --env=uk       serve --port=8004
```

| Node | URL |
|---|---|
| Pakistan | http://127.0.0.1:8001 |
| UAE | http://127.0.0.1:8002 |
| USA | http://127.0.0.1:8003 |
| UK | http://127.0.0.1:8004 |

Each node has its own storage at `nodes/<node>/storage/` — identity, keys,
event log, records, indexes, sync state. Delete a node's storage directory to
simulate a fresh install (recovery test).

## Node identity and trust

Every node holds an Ed25519 keypair. The secret key is encrypted at rest
with the node's `APP_KEY` and never leaves the node — it is not in any API
response, log line or dashboard.

```bash
php artisan --env=pakistan dstore:node:keygen   # create the identity card + keypair
php artisan --env=pakistan dstore:node:list      # identity + peer trust status
php artisan --env=pakistan dstore:node:approve uae --public-key="<base64>"
php artisan --env=pakistan dstore:node:revoke uae
```

A peer stays `pending` until its public key is pinned. Only an `approved`
peer can send an accepted message; unknown and revoked nodes are refused.

Enrolment is automatic: the presenting node proves possession of the key it
offers by signing a challenge, and the shared `DSTORE_BOOTSTRAP_TOKEN`
proves it was invited.

```bash
php artisan --env=pakistan dstore:node:enroll    # register with every peer
php artisan --env=pakistan dstore:node:discover  # probe every peer
```

## Verifying communication

Liveness (no signature required):

```bash
curl http://127.0.0.1:8001/api/dstore/v1/health
```

Signed traffic (requires an approved peer key):

```bash
php artisan --env=pakistan dstore:node:discover
```

Every other endpoint under `/api/dstore/v1/` is signed and rejects unsigned,
tampered and replayed messages.

## Replication and synchronization

Every committed event is queued per peer and pushed to them. A delivery
counts only when the peer's **applied vector** confirms it — an HTTP 200 on
its own is never treated as "synchronized". A pull writes the received event
into the local log first and relays it to the other peers, so the mesh stays
full without any coordinator.

```bash
php artisan --env=pakistan dstore:sync:status   # per-peer cursors and pending counts
php artisan --env=pakistan dstore:sync:run      # one bidirectional pass
php artisan --env=pakistan dstore:sync:daemon    # the same, in a loop (--once)
```

## Conflict resolution

Every record carries a **version vector** (per-node counter), never a
timestamp — clocks on different servers are not synchronized, so they are
never used to order writes. When two writes are concurrent, a deterministic
policy decides the winner:

| Policy | Rule |
|---|---|
| `vector-lww` (default) | Higher version-vector sum wins; a tie is broken by the writing node id. The losing state is kept in the record's history. |
| `node-priority` | A ranked node list (`DSTORE_NODE_PRIORITY`) decides; falls back to `vector-lww` when neither side is ranked. |
| `merge` | The two sides are merged field by field; a field both sides changed is decided by `vector-lww`. |

```bash
php artisan --env=pakistan dstore:record show users usr_merge_v2
php artisan --env=pakistan dstore:stats     # includes the resolved-conflict count
```

Resolved conflicts are appended to `nodes/<node>/storage/dstore/metadata/conflicts.json`
with both versions, both writers, the winner and the reason.

**All four nodes must run the same policy.** A node running `merge` while
its peers run `vector-lww` resolves the same pair of writes differently and
the mesh diverges.

Compare your records against a peer's view over the signed API. The endpoint
requires a signed envelope like every other peer call, so it is reached from a
node, not with a bare `curl`:

```bash
php artisan --env=pakistan dstore:sync:status   # what each peer has applied
```

`POST /api/dstore/v1/records/reconcile` takes `{collection, record_ids,
peer_versions}` and answers with each record's version, writer, hash and
relation to the peer (`in-sync`, `local-newer`, `peer-newer`, `concurrent`).

```bash
php artisan --env=pakistan dstore:monitor            # heartbeat every peer, update states, gossip
php artisan --env=pakistan dstore:monitor --once     # a single monitoring round
php artisan --env=pakistan dstore:sync:daemon --once # a single sync pass
```

`dstore:monitor` and `dstore:sync:daemon` are the long-running workers: starting
one creates a new runtime epoch, which is how peers tell a restart from a
reconnect. Run them under a process supervisor in production. If a node has no
supervisor, the scheduler runs the same idempotent code paths:

```bash
php artisan schedule:run   # monitor + sync each minute, backup 02:10, tombstone GC 03:10
```

A peer is `unreachable` after one missed heartbeat and `offline` after
`DSTORE_MISSES_BEFORE_OFFLINE` (default 3) consecutive misses. `degraded` means
the peer reports storage corruption but still serves. `destroyed` is never
inferred — it is an explicit administrative action.

## Recovery and backups

```bash
php artisan --env=pakistan dstore:recover            # verify → replay → rebuild indexes → resync
php artisan --env=pakistan dstore:recover --restore-snapshot
php artisan --env=pakistan dstore:index:rebuild      # rebuild indexes from the record files
php artisan --env=pakistan dstore:gc:tombstones --days=7 --dry-run
```

Recovery never lets older data win: every replayed event still passes the
version-vector rules, and a snapshot restores only records that are missing or
strictly older. Each run writes `recovery/checkpoint.json` and appends to
`recovery/checkpoints.jsonl`.

```bash
php artisan --env=pakistan dstore:backup                              # full-tree backup + hash manifest
php artisan --env=pakistan dstore:backup:list
php artisan --env=pakistan dstore:backup:verify <name>                # re-hash every file
php artisan --env=pakistan dstore:backup:restore <name> --force       # verify, then overwrite
```

Backups live in `nodes/<node>/storage/dstore/backups/<name>/` with
`manifest.json`; `DSTORE_BACKUP_RETENTION` prunes the oldest ones.

## Dashboard

Every node serves its own dashboard at `/` — there is no central UI server and
no build step (server-rendered Blade + vanilla JS polling).

| Node | Dashboard |
|---|---|
| Pakistan | http://127.0.0.1:8001/ |
| UAE | http://127.0.0.1:8002/ |
| USA | http://127.0.0.1:8003/ |
| UK | http://127.0.0.1:8004/ |

The overview shows mesh health, every node's trust and detected state, latency,
uptime, records, events and storage, plus recent conflicts, backups and the last
recovery. `/nodes/<node>` opens one node — identity, health, data, applied and
delivered vectors, collections, pending queues and issues.

Every number is read from the node's own storage, heartbeat records, event
cursors and detector state; nothing is hard-coded. A peer turns `unreachable`
then `offline` in the UI because the failure detector says so.

```bash
php artisan --env=pakistan dstore:monitor --once   # refresh every peer's state
```

The page refreshes itself every 5 s from `/dashboard/data`. **Probe now** on a
peer page sends one signed heartbeat immediately (CSRF-protected). The
dashboard is read-only and exposes no secret material, but it has no
authentication of its own — protect it with your reverse proxy in production.

## Repair and recovery commands

```bash
php artisan --env=pakistan dstore:events:repair      # sign unsigned local events, release quarantined ones
php artisan --env=pakistan dstore:records:restore    # rebuild records the store lost from the log
php artisan --env=pakistan dstore:records:reconcile  # make the store agree with the log
```

Each of them supports `--dry-run` / `--check` so nothing is changed before
you see what would change.

## Debugging

- Engine logs: `nodes/<node>/logs/node.log`
- Framework logs: `storage/logs/laravel.log`
- `php artisan dstore:stats` / `dstore:verify`
- `php artisan dstore:node:list` — peer trust status and fingerprints
- `php artisan dstore:node:discover` — probe every peer over HTTP
- `php artisan dstore:event:dump <event_id>` — inspect a replicated event
- `php artisan dstore:sync:status` — per-peer applied vectors and pending counts
- `php artisan dstore:monitor --once` — heartbeat every peer and show the detected state
- `php artisan dstore:backup:list` / `dstore:backup:verify <name>` — backup health

## Notes

- **Dropbox:** this project lives in a Dropbox-synced folder. `vendor/`
  contains tens of thousands of small files; if syncing is slow, exclude
  `dstore/vendor`, `dstore/node_modules`, and `dstore/nodes/*/storage` from
  Dropbox sync (storage dirs are regenerable node state).
- **Production:** clone this codebase to four Linux servers, one node per
  server, with its own `.env` (node id, URL, TLS). No code changes required.
