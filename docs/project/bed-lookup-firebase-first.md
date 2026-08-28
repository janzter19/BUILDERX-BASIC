# Bed Lookup Firebase-First Task Records

## Boundary

User Portal Bed Lookup reads its bed inventory from the MySQL `project_bed` projection, which is refreshed from `RBMS_BedMasterlist`. Task cards, task details, and task history are read from the authenticated Firebase client collections `project_bed_task` and `project_bed_task_log`.

Task creation allocates one `project_bed_task` document reference and its `CREATED` `project_bed_task_log` document reference with Firestore `doc(collection(...))`, then writes both in one Firebase batch. Firestore's document ID is authoritative: `bed_task_key` must equal the task document ID, `bed_task_log_key` must equal the log document ID, and any future MySQL key must match that same Firestore ID exactly. Do not create a UUID, hash, database-generated, or other business key for these records.

Before allocating the references, the browser queries the authorized `project_bed_task` composite index for the active tenant, project, bed, task, and overlapping task groups. Group values are queried in Firestore-compatible chunks of 30. It rejects a matching task unless it is terminal or soft-deleted, preserving unfinished-task prevention without deriving a document ID from the project/bed/task tuple. The browser obtains a tenant-bound custom token, validates the active bed, task, stage, required selections, authorized project, and overlapping group keys, then relies on Firestore rules for the final authorization check.

Snapshot hydration and task-update notifications always include `tenant_key == firebase.tenantKey`. Notification listeners query each authorized project key exactly, combined with task-group-key chunks of at most 30 values, then deduplicate documents that match more than one group query. The `array-contains-any` query plus the rules' `task_group_keys.hasAny(...)` authorization is query-compatible; list type is enforced when tasks are created. Required `project_bed_task` composites are `(tenant_key, project_key, task_group_keys)` for snapshot/listener reads and `(tenant_key, project_key, bed_key, task_key, task_group_keys)` for duplicate prevention. Firestore may need to build newly added composites before those reads succeed.

New task documents retain the existing MySQL metadata shape (`mysql_created_at`, `mysql_updated_at`, `mysql_sync_status = PENDING`, `mysql_synced_at`, and `mysql_deleted_at`) for compatibility. No User Portal Bed Lookup path posts task creation to PHP or inserts, updates, deletes, or relays `project_bed_task` or `project_bed_task_log` records into MySQL. No worker consumes these documents in this phase.

Task reads require the mandatory `tenant_key` and `project_key` fields; legacy documents missing either field are intentionally unreadable.

The Firebase listener and reload hydration both read task documents and their logs, so a Firebase-only task remains visible after the user performs the normal page refresh/load. The User Portal does not refresh Bed Lookup merely because the browser receives focus or changes visibility; refresh is explicit or caused by a task-change notification. Messenger synchronization and the managed-bed projection remain separate concerns.

Administrator Bed Lookup has a separate boundary. Its per-bed `resync_project_bed` action reads the external `RBMS_BedMasterlist`, updates the MySQL `project_bed` projection in a transaction, verifies the MySQL read-back, and returns. It does not rebuild analytics or write bed, task, or analytics documents to Firebase. The external bed-masterlist refresh is intentionally preserved; this boundary must not be confused with TRAVERSE or the Firebase-first application writes.

## Focused verification

- `php -l app/foundation.php && php -l index.php`
- `npm run build` from `frontend/`
- `git diff --check`
- `php tests/portal-bed-task-firebase-only-static.php`

Live Firebase writes and rule deployment require the configured Firebase project and authenticated tenant account; static checks must not be reported as live persistence evidence.
