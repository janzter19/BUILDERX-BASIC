# RBMS Android Offline-First Room Synchronization

Status: Architecture and implementation specification; SQLite migration slice applied  
Scope: RBMS Android tenant data, task lists, task stages, task responses, task history, and offline submissions  
Primary source of truth: Firebase Firestore  
Target local database: Android Room, backed by SQLite  
Current local database: tenant-scoped `SQLiteOpenHelper` backed by `rbmsv4_offline.db`

## 1. Purpose

The Android client must remain usable when Firebase is slow, unavailable, or temporarily disconnected. The application must:

- preserve the last successfully synchronized task data locally;
- show cached data immediately while synchronization runs;
- receive realtime Firestore changes when connected;
- repair missed changes with a full resynchronization;
- accept task-stage submissions offline;
- retry pending submissions safely when connectivity returns; and
- detect conflicts instead of silently overwriting another user’s stage update.

This design is called an **offline-first Room/SQLite cache with realtime synchronization, reconciliation, and an outbox**.

It is not Retrieval-Augmented Generation. RAG is an AI retrieval pattern. This feature is local data persistence and synchronization.

## 1.1 Offline-first, fire-and-forget, and offline-friendly behavior

These three terms describe the user experience and the persistence contract:

### Offline-first

The local Room database is the first read and write boundary for the UI. The app should not wait for Firebase before displaying known data or accepting a valid user action.

```text
Room data available → render immediately
Firebase available   → synchronize in the background
Firebase unavailable → keep using valid local data
```

Offline-first does not mean that local data is always current. The UI must show a stale or synchronization indicator when the last server-backed refresh is older than the configured freshness window.

### Fire-and-forget

For a user mutation, “fire-and-forget” means:

1. validate the action locally;
2. commit the local state change and outbox record in one Room transaction;
3. return control to the user immediately; and
4. deliver the Firebase operation asynchronously.

It must not mean discarding the request or ignoring its result. Every fire-and-forget operation must have a durable mutation ID, retry state, conflict state, and final outcome. The UI may show `Pending sync`, `Synced`, `Conflict`, or `Failed`.

### Offline-friendly

An offline-friendly screen:

- opens using the last valid Room snapshot;
- allows actions that can be validated without Firebase;
- queues writes instead of blocking on a network timeout;
- preserves pending work if the process is killed;
- retries when connectivity returns; and
- explains stale data, conflicts, and permanent failures in user-facing language.

Actions that require authoritative server validation must remain visibly pending until Firebase confirms them. The app must never present an unconfirmed local stage as permanently synchronized.

## 2. Current implementation baseline

The current Android project has partial offline support:

- `TenantOfflineStore` stores JSON payloads in `SharedPreferences`.
- `HomeActivityRagStore` caches the Home screen model using that store. Despite the class name, it is a cache, not AI RAG.
- `TenantOfflineQueueStore` and `TenantOfflineModels` provide generic queued-mutation and retry state.
- Firestore repositories and listeners currently read task data directly from Firebase.
- `BedTaskListRealtimeMonitor` listens to `project_bed_task_summary` and individual `project_bed_task` documents.
- Task-stage submission currently uses an online Firestore transaction that updates `project_bed_task` and creates `project_bed_task_log`.

The current cache is not a normalized local task database. The target implementation adds Room and moves task-list and task-history reads behind Room.

### 2.1 Applied implementation slice

The current Android project now has the durable SQLite boundary needed for the migration:

- `TenantOfflineDatabase` creates tenant-scoped `records`, `sync_state`, and `outbox_mutation` tables.
- Login stores the validated user profile locally after Firebase authentication. Passwords are never stored.
- Tenant binding/configuration is cached locally after client-code verification.
- `project_setting` snapshots are cached by the splash/login settings reader.
- Floor snapshots, Home task summaries, task-list task documents, task-stage catalogs, task details, and task history are written to the local database when Firebase returns them.
- Cached floors, Home summaries, task cards, stage catalogs, and task details are emitted before an online refresh when available.
- A stage transition commits its optimistic task update and durable outbox mutation in one local SQLite transaction, then returns immediately to the user.
- `TenantOfflineSyncWorker` delivers pending stage transitions using a Firebase transaction, a mutation-key log document, expected-stage validation, retry state, and conflict state.
- The worker uses the complete `project_bed_task_log` projection required by Firestore Rules, including the task identity fields, stage fields, actor fields, timestamps, and exact allowed-key set.
- App start schedules periodic network-constrained sync; Home start also requests a one-time sync. WorkManager preserves pending work across process death and retries when connectivity returns.

This is deliberately an incremental migration. The existing monitor callbacks still deliver the screen state, while their first emission can now come from SQLite. The final target remains DAO/Flow-backed ViewModels, so the next migration step is to make Room/SQLite queries—not monitor callbacks—the only UI read path.

## 2.2 Login-to-current lifecycle

The applied data path is:

```text
Tenant code
  → verify configuration
  → encrypted tenant binding + local configuration snapshot

Login
  → Firebase Auth and project_user validation
  → encrypted session/profile compatibility cache + SQLite profile record

Settings/splash
  → project_setting/{project_key}
  → SQLite project_setting record + image-file cache

Home
  → SQLite profile/floor/summary records first
  → Firestore listeners upsert records and reconcile removals

Task list/details
  → SQLite task/catalog/history records first
  → Firestore refresh updates the same records

Stage submit
  → SQLite task patch + outbox mutation in one transaction
  → WorkManager/Firebase transaction
  → COMPLETED or CONFLICT, with the Firebase log written once
```

## 3. Target architecture

```text
                 Firebase Firestore
          project_task, project_bed_task,
       project_bed_task_log, summaries, floors
                         │
          realtime listeners + full resync
                         │
                SyncCoordinator
          ┌──────────────┴──────────────┐
          │                             │
     Room/SQLite database          Outbox worker
          │                             │
       Flow/queries                 Firebase transaction
          │                             │
                       Android UI
```

The UI never depends directly on a Firestore callback to render task data. Firestore data is first written to Room, and the UI observes Room `Flow` values.

## 4. Tenant and database isolation

Every Room row must be tenant-scoped. Use a stable scope key derived from the verified tenant binding:

```text
scope_key = hash(tenant_id + branch_key + project_key)
```

Every entity must contain `scope_key`. Queries must always filter by the active scope. On sign-out or tenant switch:

1. stop Firestore listeners;
2. stop the sync worker for the previous scope;
3. clear or retain only the previous scope’s encrypted local rows according to retention policy;
4. never display rows from another scope; and
5. start the new scope only after authentication and tenant verification succeed.

## 5. Room database schema

Room is the recommended Android API over SQLite. The database should use migrations and transactions rather than ad-hoc JSON blobs.

### 5.1 `task_catalog`

Stores `project_task` documents used by the task menu.

| Column | Type | Notes |
|---|---|---|
| `scope_key` | `TEXT` | Tenant partition; part of primary key |
| `task_key` | `TEXT` | Firestore document identity; part of primary key |
| `task_code` | `TEXT` | Display code |
| `task_title` | `TEXT` | Display title |
| `task_type` | `TEXT` | `PRIMARY` or `SECONDARY` |
| `task_color_hex` | `TEXT` | Task color |
| `task_status` | `TEXT` | Active status |
| `task_group_keys_json` | `TEXT` | Authorized group projection |
| `updated_at` | `INTEGER` | Local epoch milliseconds from server metadata |
| `sync_revision` | `INTEGER` | Monotonic server revision when available |
| `is_deleted` | `INTEGER` | Soft-delete marker |

Primary key: `(scope_key, task_key)`.

### 5.2 `task_stage`

Stores active `project_task_stage` projections embedded in or synchronized from the task catalog.

Primary key: `(scope_key, task_stage_key)`.

Important fields: `task_key`, `stage_label`, `stage_description`, `stage_color_hex`, `stage_sort_order`, `stage_status`, `stage_ends_task`, and `sync_revision`.

### 5.3 `task_stage_response`

Stores active `project_task_stage_response` records.

Primary key: `(scope_key, task_stage_response_key)`.

Important fields: `task_stage_key`, `response_label`, `response_description`, `response_color_hex`, `response_sort_order`, `response_status`, and `sync_revision`.

### 5.4 `bed_task`

Stores the task cards and details shown by the Android app.

Primary key: `(scope_key, bed_task_key)`.

Required fields include:

- bed identity and location;
- task identity and type;
- `task_status`;
- `current_task_stage_key`, `current_stage_label`, `current_stage_color_hex`;
- `task_stage_key`, `stage_label`, `stage_color_hex`;
- bed source and treatment names;
- remarks;
- `mysql_updated_at` for source display;
- `updated_at` as a Firestore server timestamp;
- `sync_revision`; and
- `last_seen_sync_run_id`.

The two stage field sets must be updated together. They are aliases used by existing Firebase projections and must not diverge.

### 5.5 `bed_task_log`

Stores `project_bed_task_log` history records locally.

Primary key: `(scope_key, bed_task_log_key)`.

Store the event type, stage fields, selected response fields, actor, remarks, `created_at`, `updated_at`, and `sync_revision`. Add an index on `(scope_key, bed_task_key, created_at)` for history screens.

### 5.6 `sync_state`

Stores synchronization state per scope and collection.

| Column | Type | Purpose |
|---|---|---|
| `scope_key` | `TEXT` | Tenant partition |
| `collection_name` | `TEXT` | Firestore collection |
| `last_successful_sync_at` | `INTEGER` | Last completed reconciliation |
| `last_realtime_event_at` | `INTEGER` | Last listener event applied |
| `last_server_revision` | `INTEGER` | Last accepted revision |
| `last_sync_run_id` | `TEXT` | Reconciliation identity |
| `state` | `TEXT` | `IDLE`, `RUNNING`, `STALE`, or `FAILED` |
| `last_error` | `TEXT` | User-safe diagnostic code |

Primary key: `(scope_key, collection_name)`.

### 5.7 `outbox_mutation`

Stores offline writes until Firebase confirms them.

| Column | Type | Purpose |
|---|---|---|
| `mutation_id` | `TEXT` | Unique idempotency key |
| `scope_key` | `TEXT` | Tenant partition |
| `operation_type` | `TEXT` | For example `ADVANCE_BED_TASK_STAGE` |
| `aggregate_key` | `TEXT` | `bed_task_key` |
| `payload_json` | `TEXT` | Validated mutation payload |
| `expected_stage_key` | `TEXT` | Conflict precondition |
| `expected_sync_revision` | `INTEGER` | Optional conflict precondition |
| `status` | `TEXT` | `PENDING`, `RETRYING`, `COMPLETED`, `FAILED`, `CONFLICT` |
| `attempt_count` | `INTEGER` | Retry count |
| `created_at` | `INTEGER` | Local creation time |
| `updated_at` | `INTEGER` | Last attempt time |
| `last_error` | `TEXT` | Safe error code |

Unique index: `(scope_key, mutation_id)`.

## 6. Realtime synchronization

Firestore listeners receive `ADDED`, `MODIFIED`, and `REMOVED` document changes. The listener must not update the UI directly.

For every snapshot:

1. identify the tenant scope;
2. inspect `snapshot.metadata.isFromCache` and `hasPendingWrites`;
3. convert the document to a validated Room entity;
4. apply the change and its sync metadata in one Room transaction;
5. upsert `ADDED` and `MODIFIED` records;
6. soft-delete or remove `REMOVED` records; and
7. expose the updated Room query to the UI.

Cache-originated snapshots are useful for immediate display but must mark the scope as stale until a server-backed snapshot confirms synchronization.

Realtime listeners are incremental delivery. They are not a replacement for full reconciliation because listeners may be stopped, permissions may change, a query may change, or the process may be killed.

## 7. Full resynchronization and reconciliation

Run a full resync on these triggers:

- user login succeeds;
- app starts or returns to the foreground;
- Firebase reconnects after an offline period;
- user manually refreshes;
- periodic WorkManager execution; and
- after a conflict or permission refresh.

### Full resync algorithm

1. Create a unique `sync_run_id`.
2. Set `sync_state.state = RUNNING`.
3. Fetch authoritative server data for the active tenant and authorized groups.
4. Validate tenant, group, document identity, status, and field types.
5. Upsert every received document into Room and set `last_seen_sync_run_id`.
6. Fetch dependent collections in dependency order:

   ```text
   task_catalog
       → task_stage
       → task_stage_response
   bed_task_summary
       → bed_task
       → bed_task_log
   ```

7. Only after all required pages/collections succeed, remove or soft-delete rows in that scope whose `last_seen_sync_run_id` is older than the current run.
8. Commit sync metadata as `IDLE` with `last_successful_sync_at`.
9. If any required collection fails, retain the previous local rows, mark the scope `STALE` or `FAILED`, and never delete data based on an incomplete response.

A full resync is therefore a reconciliation pass, not a destructive “clear and refill” operation.

## 8. UI read path

The task list, task details, stage catalog, and history screen must read from Room:

```text
Room DAO Flow → ViewModel → Activity/Compose UI
```

The UI should:

- show cached records immediately;
- show a small “Last synchronized” or stale indicator when data is cache-only;
- show a loading state only when no local data exists;
- continue displaying cached records if Firebase fails;
- show a non-blocking sync error instead of replacing valid data with an empty state; and
- observe Room changes caused by either realtime sync or outbox confirmation.

## 9. Offline submissions and the outbox

The stage submission flow must use a local database transaction first.

```text
User selects response
        │
        ▼
Room transaction:
  update local bed_task stage optimistically
  insert outbox_mutation = PENDING
  insert local pending bed_task_log
        │
        ▼
UI immediately shows the new stage and pending indicator
        │
        ▼
WorkManager retries when network is available
        │
        ▼
Firestore transaction updates project_bed_task
and creates project_bed_task_log
        │
        ▼
Room marks mutation/log SYNCED
```

The outbox payload must include:

- tenant scope;
- `bed_task_key`;
- expected current stage key and revision;
- next stage key, label, and color;
- selected response key and label;
- actor identity;
- client mutation ID; and
- creation time.

The client mutation ID must be deterministic or stored durably so retries do not create duplicate history records.

## 10. Retry worker

Use a unique WorkManager job per tenant scope, with `NetworkType.CONNECTED`.

The worker should:

1. load the oldest `PENDING` or `RETRYING` mutation;
2. refresh authentication only when needed;
3. send the mutation using the Firestore transaction and precondition;
4. mark the outbox row `COMPLETED` on acknowledgement;
5. mark the local log as synchronized;
6. mark `CONFLICT` when the server stage/revision no longer matches;
7. mark `FAILED` after the retry policy is exhausted; and
8. trigger a targeted resync after success or conflict.

Use exponential backoff with a bounded maximum. Do not retry validation, authorization, or permanent conflict errors indefinitely.

## 11. Conflict handling

Every mutable Firebase task document must have a reliable server value:

- `updated_at: Timestamp`, or
- a monotonic `sync_revision`.

The preferred transition precondition is:

```text
server.current_stage_key == mutation.expected_stage_key
AND
server.sync_revision == mutation.expected_sync_revision
```

If the condition fails:

1. do not overwrite the server task;
2. do not create a successful transition log;
3. mark the outbox mutation `CONFLICT`;
4. refresh the task and history from Firebase; and
5. tell the user that the task changed elsewhere and requires review.

Do not use silent last-write-wins for stage transitions.

## 12. Reconnect behavior

When connectivity returns:

1. Room remains the immediate UI source.
2. The realtime listener reconnects and applies incremental events.
3. The outbox worker sends pending mutations.
4. A full reconciliation runs after authentication and listener recovery.
5. Local stale flags are cleared only after a server-backed sync succeeds.

The ordering prevents a stale cached snapshot from overwriting a newer outbox result.

## 13. Security requirements

- Keep tenant and group checks in Firebase Rules; never rely only on Room filtering.
- Validate all document keys and scope values before writing Room.
- Never allow a local cache to grant authorization.
- Store only the minimum user and task data needed for the offline experience.
- Clear tenant-scoped data on sign-out when policy requires it.
- Keep actor identity and mutation IDs in every submitted history record.
- Permit only stage fields to be changed by the mobile transition operation.
- Require the log and task update to be committed atomically on Firebase.

## 14. Migration plan

### Phase 1: Local database foundation (applied with SQLiteOpenHelper; Room-compatible schema)

- Add the local database and versioned schema migrations.
- Create tenant-scoped records, sync metadata, and an outbox.
- Keep the existing SharedPreferences cache working as a fallback.

### Phase 2: Read mirror

- Write Firestore task, stage, response, summary, and log reads into Room.
- Add realtime listener-to-Room adapters.
- Add full reconciliation and sync-state reporting.

### Phase 3: Room-backed UI (remaining)

- Change Home, task list, task details, and history ViewModels to observe Room.
- Show cached/stale state clearly.
- Stop rendering directly from Firebase callbacks.

### Phase 4: Offline outbox

- Convert stage submission to a Room transaction plus outbox entry.
- Add WorkManager retry and conflict handling.
- Reconcile after successful writes.

### Phase 5: Cleanup

- Retain SharedPreferences only for small configuration/session values.
- Remove task-data JSON cache paths after migration verification.
- Keep `TenantOfflineQueueStore` only for operations that are not migrated to Room, or replace it with the Room outbox.
- Rename `HomeActivityRagStore` to `HomeActivityCacheStore` to avoid confusing cache persistence with AI RAG.

## 15. Acceptance tests

### Read synchronization

- First online login populates Room.
- App restart with Firebase unavailable shows the last task list.
- A realtime task modification updates Room and the UI.
- A realtime removal removes or soft-deletes the local record.
- A missed realtime event is repaired by full resync.
- A failed partial resync does not delete valid cached records.
- Switching tenants never displays the previous tenant’s tasks.

### Outbox

- Submit while online completes and creates exactly one Firebase log.
- Submit while offline updates the local UI and creates one `PENDING` mutation.
- Reconnect retries and eventually marks the mutation `COMPLETED`.
- Killing and restarting the app preserves the pending mutation.
- Replaying the same mutation does not create duplicate logs.
- A stale stage produces `CONFLICT` and does not overwrite Firebase.
- Authorization failure becomes a permanent failure visible to the user.

### Recovery

- Listener disconnect/reconnect resumes without clearing Room.
- Manual refresh runs a full reconciliation.
- WorkManager retry uses bounded backoff.
- Cache-only data is visibly marked stale.
- Empty state is shown only when Room has no valid records, not when Firebase is temporarily unavailable.

## 16. Definition of done

The migration is complete only when:

- all task-related Firebase reads are mirrored into Room;
- the UI reads task data and history from Room;
- realtime changes and full resync both update Room;
- stage submissions are durable offline outbox mutations;
- Firebase writes are idempotent and conflict-checked;
- tenant isolation is enforced in Room queries and Firebase Rules;
- cold-start offline, reconnect, conflict, and retry tests pass; and
- a direct read-back confirms both the Firebase task stage and its corresponding log record.
