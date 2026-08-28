# TRAVERSE: Firebase-to-MySQL Sync Master

## Implemented scope

This inbound service treats Firestore as the mutation source and MySQL as a read projection. It supports exactly these allowlisted mappings:

| Firestore collection | MySQL table | authoritative key |
| --- | --- | --- |
| `project_bed_task` | `project_bed_task` | `bed_task_key` |
| `project_bed_task_log` | `project_bed_task_log` | `bed_task_log_key` |
| `project_messenger_chat` | `project_messenger_chat` | `chat_key` |
| `project_messenger_chat_attachment` | `project_messenger_chat_attachment` | `attachment_key` |
| `project_messenger_chat_reaction` | `project_messenger_chat_reaction` | `reaction_key` |
| `project_group` | `project_group` | `group_key` |
| `project_position` | `project_position` | `position_key` |
| `project_user_group` | `project_user_group` | `assignment_key` |
| `project_user` | `project_user` | `user_key` (Firebase Auth UID) |
| `project_bed_source` | `project_bed_source` | `bed_source_key` |

The Firestore document ID must equal the matching key field and fit the `VARCHAR(255)` identity contract. A mismatch or longer identity is dead-lettered before MySQL projection. `project_bed_task_summary`, MySQL-first projection collections, PHP/browser MySQL writes, general MySQL-to-Firebase synchronization, BuilderX, and phase-management code are excluded. The only Firebase write made by this service is the conditional synchronization acknowledgement described below.

`project_bed_source/{bed_source_key}` is Firebase-first. Bed Source create,
edit, status/soft-delete/restore, and reorder write Firestore first. New
records use Firestore's generated document ID and copy it to `bed_source_key`;
no UUID or MySQL-generated key is accepted. The document contains the source
business fields, `firebase_collection`, `firebase_created_at`,
`firebase_updated_at`, `firebase_deleted_at`, and the five `mysql_*` lifecycle
fields. Every mutation starts with `mysql_sync_status = PENDING`; TRAVERSE
changes it to `SYNCED` only after exact MySQL read-back. Browser Bed Source
actions no longer write the MySQL business table directly.

The four Portal contracts are definition/profile projections: groups are not members arrays, positions require an explicit `group_key`, and `project_user_group` is one assignment per document. `project_user` is profile-only and excludes relationship fields and all password/hash fields; Firebase Auth UID is authoritative and must equal both the Firestore document ID and `user_key`. The service never creates or changes Auth credentials, passwords, or claims. Disabled/deleted users and identity mismatches are terminal dead letters. The legacy `user_password_hash` column is nullable for projection compatibility but remains outside the Firebase payload; obsolete audit-owner columns are not part of the active `project_user` contract. Existing deployments whose `project_user_group` table is the legacy aggregate group-definition schema must remediate that schema/writer contract before assignment rows can project; the service fails closed and does not invent credentials or remap assignments.

## Data flow and recovery

Each allowlisted collection has its own low-latency `PENDING` listener and its own recurring paginated `PENDING` scanner. Existing pending documents are scanned at startup; there is no baseline skip. Listener and scanner failures are isolated by collection. Discovery validates the document, preserves Firestore `updateTime` as `seconds:nanoseconds` with all nine nanosecond digits, fingerprints the lossless payload representation, and inserts a revision-deduplicated queue row.

Workers poll independently from the 30-second default full scan interval. Global concurrency is bounded by `FIREBASE_MYSQL_SYNC_WORKER_CONCURRENCY`; only one revision per collection/table is active at a time. A long repair or poison row therefore does not stop ingestion or processing for another table. Leases recover abandoned claims after process failure.

The durable MySQL control tables are:

- `firebase_mysql_sync_collection_registry`
- `firebase_mysql_sync_field_registry`
- `firebase_mysql_sync_queue`
- `firebase_mysql_sync_projection_state`
- `firebase_mysql_sync_migration_history`
- `firebase_mysql_sync_attempt_history`

Queue revisions are unique by collection, document ID, and full-precision source revision. State transitions and attempt-history insertion occur in one transaction and require the current worker lease. The normal state flow is:

`QUEUED -> CLAIMED -> ACK_PENDING -> ACKED`

Transient projection failures use `RETRY_WAIT` with bounded exponential backoff and jitter. Invalid identifiers/data, unsupported or lossy values, conflicts, schema-limit violations, and exhausted retries use `DEAD_LETTER`. An old or missing source revision, a stale projection, or a conditional acknowledgement that finds changed state uses the explicit terminal state `SUPERSEDED`.

`ACK_PENDING` means the MySQL business projection and projection-state fence already committed and passed exact read-back. The service conditionally changes Firebase synchronization metadata to `SYNCED` with a server timestamp, reads that committed metadata back, then updates only MySQL `mysql_sync_status` and `mysql_synced_at`. Compiled `ON UPDATE` business timestamps such as `updated_at` are explicitly self-assigned and verified unchanged. The queue becomes `ACKED` only after metadata parity passes exact MySQL read-back.

Reclaiming `ACK_PENDING` never repeats registry, repair, or business projection work. If Firebase already contains the prior successful acknowledgement, the projection-state revision/fingerprint fence proves ownership and the service safely resumes only MySQL metadata finalization. Transient acknowledgement/finalization errors return to `ACK_PENDING` with a released lease and bounded backoff; exhausted attempts dead-letter without reprojecting. A newer `PENDING` Firestore mutation cannot be acknowledged by an older queue revision.

## Transactional projection and exact read-back

`firebase_mysql_sync_projection_state` is locked by collection and document ID in the same transaction that writes the target row. Its full source revision and payload fingerprint fence out older queue rows. A same-revision/same-fingerprint replay is idempotent; the target is not rewritten. A same-revision/different-fingerprint event is a terminal conflict.

Before commit, the worker reads back every projected field and the projection-state revision/fingerprint. Values are normalized by their declared registry type and compared losslessly. Any truncation, coercion, missing row, or fence mismatch rolls the transaction back. Worker sessions use `STRICT_ALL_TABLES`; synchronized values are never sliced or truncated.

## Canonical field registry and types

Firestore has no semantic field order. Seed fields are persisted in the declared per-collection order. The `project_bed_task` seed contains the complete current Portal task payload; `project_bed_task_log` contains that full shape plus log identity/event/actor fields. Known seeds do not consume the dynamic-field budget. A partial older registry is transactionally renumbered under the collection lock so it converges to the same canonical seed order. Valid unknown top-level fields then receive the next persisted ordinal. Total-field and per-document new-field limits are checked before any registry write. Type/size observations update transactionally and only through monotonic promotion.

The field registry defines the mirrored-column order. Ordering differences are detected independently of type differences. Repairs add, widen, or move mirrored columns into that order while retaining unrelated legacy/operational MySQL columns. Existing storage-compatible types are preserved even when their spelling differs from the generic registry type. When a column is moved, the service strips non-executable `DEFAULT_GENERATED` information-schema metadata while preserving the executable `DEFAULT` and `ON UPDATE` clauses, along with nullability, unsigned semantics, and collation. All allowlisted contracts are Firebase-field-driven and do not seed hardcoded business fields absent from the document. TRAVERSE supplies only missing synchronization metadata fields. A newly created projection table uses exactly `xId INT(10) AUTO_INCREMENT PRIMARY KEY`; the Firebase identity key remains the unique upsert key. Existing `project_user.x_id` requires a controlled rename/migration to `xId` before the new contract is activated. Collection/table routing and document-key mapping remain compiled system configuration, not business fields. The service never auto-drops or narrows an existing column. An incompatible existing definition fails safely for manual review.

Implemented value policy:

- Contract strings use logical text registry types; new unconstrained columns use the applicable `TEXT` family. Existing `VARCHAR(n)` remains when the incoming encoded character count fits, and existing `ENUM` remains when the incoming value is one of its declared members. An overflow safely widens `VARCHAR`/`ENUM` to a sufficiently large text type while preserving existing data/default/null semantics. Authoritative identity keys remain compatible `VARCHAR` and are never mapped to JSON.
- Dynamic strings use `TEXT`, `MEDIUMTEXT`, or `LONGTEXT` based on utf8mb4 byte length. Thresholds are 65,535, 16,777,215, and 4,294,967,295 bytes.
- Bytes use the matching `BLOB` family and are base64-validated where provided by the Firebase SDK.
- Firestore signed 64-bit integers use exact string-backed MySQL driver handling. Existing `INT` families remain when the value fits and widen to `BIGINT` only when required. Existing `BIGINT UNSIGNED` is preserved for nonnegative values; a negative signed value requires the mathematically wider `DECIMAL(20,0)`, which contains both the complete unsigned-BIGINT range and the signed Firestore value. Unsafe JavaScript integers and out-of-range BigInts are rejected.
- Finite fractional numbers use `DECIMAL(65,30)` only when their exact non-exponential representation fits 35 integer and 30 fractional digits. Out-of-range values are rejected.
- Booleans use `TINYINT(1)`.
- New timestamp columns use UTC `DATETIME(6)`. Existing `TIMESTAMP[(p)]` or `DATETIME[(p)]` is preserved when the incoming UTC value is within its range and exactly representable at its fractional precision. It widens to `DATETIME(6)` only for additional precision/range. Accepted inputs are Firebase Timestamp values whose nanoseconds are exactly microsecond-representable, or ISO/SQL-shaped `YYYY-MM-DD[T ]HH:mm:ss[.ffffff][Z]` strings interpreted as UTC. Invalid dates, sub-microsecond precision, and values outside MySQL's supported year range are rejected; timestamp-plus-arbitrary-string never promotes to JSON.
- Maps/arrays use deterministic tagged JSON. Nested timestamps, bytes, references, geopoints, BigInts, maps, arrays, booleans, numbers, strings, and null are preserved. Cycles, undefined values, non-finite numbers, unsafe integers, invalid reference paths, and out-of-range geopoints are rejected.
- Top-level references use text paths; geopoints use deterministic JSON; null defers type inference and projects as SQL `NULL` once the field has an established type. A revision that introduces a new null-only field retries without schema/projection mutation until a later observation establishes its type, then follows the normal bounded-attempt policy.

## Repair locking and verified backups

Registry allocation and schema repair use separate deterministic advisory-lock names derived only from compiled allowlisted mappings. Schema repair holds a dedicated MySQL connection and `GET_LOCK` for the target table, re-reads the persisted registry and actual `information_schema`, then keeps the lock through backup, DDL, and post-verification. Different tables use different locks.

Every repair of an existing table that requires ADD, widening, or reordering first creates a full backup named exactly `{table}_YYYY_MM_DD_HH_mm` in `FIREBASE_MYSQL_SYNC_BACKUP_TIMEZONE` (`Asia/Manila` by default). First creation uses `CREATE TABLE ... LIKE`, copies all rows, and verifies actual schema fingerprint, row count, and `CHECKSUM TABLE` when available. Migration history stores source/backup counts and checksums, pre/post actual schema fingerprints, status, error code, and timestamps.

A same-minute backup may be reused only after two fresh comparisons: the current source table's actual schema fingerprint, row count, and available checksum must still equal the recorded pre-repair baseline, and the existing backup's actual schema fingerprint, row count, and available checksum must also equal that baseline. Source drift or backup drift records a redacted `FAILED` history row and stops before DDL. Consequently, once an earlier repair in that minute has changed the source schema or rows, the baseline cannot be reused for another repair; processing must wait for a new minute/baseline or receive manual intervention. Backup creation/copy/verification failure likewise records `FAILED` when the database remains available. DDL/post-verification failure retains the backup and records `FAILED`. DDL auto-commit is assumed; the service never claims rollback or automatic restoration.

`FIREBASE_MYSQL_SYNC_BACKUP_MAX_TABLE_BYTES` is a configurable estimated-table-size safety ceiling based on `information_schema.tables`. It is not a filesystem free-space measurement—MySQL does not expose portable host free-space information. Live deployment must add an external disk/capacity monitor and choose a ceiling with operational headroom. Backups have retention metadata in migration history but are never automatically deleted. Restore and cleanup are manual, separately approved operations.

## Security and deployment topology

Dynamic SQL identifiers come only from the nine compiled mappings or the validated persisted field registry. Values are parameterized. Telemetry contains collection/document identifiers, state, and normalized error codes, never full document payloads or secrets.

Use a dedicated service account and a dedicated MySQL user. The Firebase identity needs read access to the nine collections plus transaction permission to update only synchronization metadata on those documents. The MySQL identity needs session/transaction access, SELECT/INSERT/UPDATE on the six control tables and nine target tables, CREATE/ALTER for approved target repairs and backup tables, `information_schema` visibility, `CHECKSUM TABLE`, and `GET_LOCK`/`RELEASE_LOCK`. It must not receive DROP, TRUNCATE, unrelated-schema, or administrative privileges.

### Verified current host state (2026-08-26)

- `rbmsv4-firebase-mysql-sync.service` is installed and active/running. It is intentionally disabled at boot during the canary phase.
- The conflicting legacy `rbmsv4-firebase-sync.service` is stopped and disabled. The interactive `nodemon test.js` prototype process and its child are stopped, and no separate service, timer, or cron startup entry was found, so the prototype is not currently racing the master.
- The worker uses a dedicated root-managed environment file and a least-privilege MySQL synchronization account. No secret is stored or reproduced in this document.
- The deployment contains the six control tables listed above. The verified canary covered the original five mappings; the source now additionally allowlists the four Portal contracts above, pending compliant writers/schema readiness. `project_bed_task_summary` remains excluded.
- The final Messenger canary reached Firebase `SYNCED`, queue `ACKED`, a matching projection revision/fingerprint fence, and an exact all-field MySQL read-back. The latest migration is `COMPLETED`; all five collections have zero `PENDING` documents, and the schema repair plan is empty.
- Two historical failed canary migration audit records and their verified backups are retained. Projected row values remained intact, and the nullable defaults altered during the failed attempts were remediated.
- The next explicit operational decision is whether to enable the unit at boot. After that decision, design and approve the `project_bed_task_summary` updater as a separate phase; it is not part of this master deployment.

### Source and new-host deployment behavior

The unit first reads the project `.env` for existing Firebase settings, then optionally reads `/etc/rbmsv4-firebase-mysql-sync.env`; systemd's later file takes precedence. A new-host deployment must create that second file as `root:root` mode `0600` and place only the dedicated `FIREBASE_MYSQL_SYNC_DB_HOST`, `FIREBASE_MYSQL_SYNC_DB_PORT`, `FIREBASE_MYSQL_SYNC_DB_USER`, `FIREBASE_MYSQL_SYNC_DB_PASSWORD`, and `FIREBASE_MYSQL_SYNC_DB_NAME` values there. Do not put the dedicated database password in the web-root `.env`. The source tree does not create or contain this secret file. Although the source unit marks the root-managed file optional so offline validation remains portable, the worker fails closed at configuration loading when any required database value is absent.

The supplied `deploy/systemd/rbmsv4-firebase-mysql-sync.service` remains a separate inbound unit. It does not import the legacy outbound supervisor or the preserved prototype. Installation, enablement, restart, rollback, and credential provisioning remain explicit host operations rather than source-tree side effects.

## Verification evidence and remaining approval gates

Offline tests cover allowlisting/identifier safety, full-precision revisions, timestamp and nested-value handling, exact numeric bounds, utf8mb4/BLOB thresholds, queue dedupe/leases/transition guards, retry jitter/dead-letter decisions, crash-safe ACK-only replay and metadata parity with `updated_at` preservation, stale projection fencing, exact all-field read-back, multi-page startup scanning, complete first-run task/log seed bootstrap, concurrent/canonical ordinal allocation and preflight rejection, representative Portal Messenger `ENUM`/`VARCHAR`/`INT`/`BIGINT UNSIGNED`/`TIMESTAMP` compatibility and widening, real combined `EXTRA` reconstruction, schema-order detection, per-table lock release, first/reused/rejected backups, backup-failure audit, cross-table isolation, and protected-path/static rules.

Offline tests alone do not prove a live lifecycle. The verified 2026-08-26 Messenger canary above supplies live evidence for that bounded case, not universal lifecycle coverage. Separate approval and monitoring remain required for broader cases: new row, update, soft delete, simultaneous revisions, crash after MySQL commit, expired lease, long repair with another table active, dead-letter recovery, same-minute repair reuse, service restart with a multi-page backlog, and representative MySQL/Firestore read-back for the remaining allowlisted collections.
# Master Sync contract note: legacy group-table collision

`project_group` and `project_position` receive additive lifecycle metadata and
sync indexes during schema bootstrap. Existing values are preserved.

`project_user_group` is not automatically converted when it already exists in
the legacy group-definition shape. The approved assignment contract requires
one row per `project_key + group_key + user_key`, with `assignment_key` as the
real Firebase document ID and `position_key` as the selected position. Adding
those fields to the legacy table would leave existing required group-definition
columns and uniqueness rules in conflict, so the assignment mapping remains
blocked until a separately approved backup/rename-and-cutover migration is
prepared. No legacy row is transformed or discarded by bootstrap.

## Task Builder Firebase-first contract

Administrator Task Builder mutations use these allowlisted mappings:

| Firestore collection | MySQL projection | authoritative key |
| --- | --- | --- |
| `project_task` | `project_task` | `task_key` |
| `project_task_stage` | `project_task_stage` | `task_stage_key` |
| `project_task_stage_response` | `project_task_stage_response` | `task_stage_response_key` |

Task, stage, response, connection, canvas, and ordering actions write the
corresponding Firebase document first, use the real Firestore document ID as
the `*_key`, set `mysql_sync_status = PENDING`, and return before the legacy
MySQL CRUD branch can execute. TRAVERSE discovers the pending document and
performs the MySQL projection plus exact read-back. Delete actions are
soft-deletes in Firebase and retain the lifecycle values for projection.
Legacy MySQL helper implementations remain in source temporarily for
controlled cleanup, but are not the active Task Builder action path.
