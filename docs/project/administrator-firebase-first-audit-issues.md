# Administrator Firebase-First Audit Issues

**Audit date:** 2026-08-27  
**Scope:** `/administrator/` workflows in the RBMS Portal checkout  
**Audit type:** Read-only code audit; no files, live data, services, or database were changed.

## Summary

The Administrator area is **not yet fully Firebase-first**. Group and Position create/status paths are mostly Firebase-first, and Bed Source/Bed Treatment active save/status paths are Firebase-first. However, several Administrator workflows still write to MySQL directly, or retain legacy MySQL code that can be reactivated by future changes.

The Bed masterlist/API refresh is an intentional exception and must remain unchanged unless separately approved.

## Remaining direct or non-clean paths

| Area | Status | Finding |
|---|---|---|
| Branch Management | Direct MySQL | `builder_branch` create, edit, and status use direct `INSERT`/`UPDATE`. |
| Project Management | Direct MySQL | `builder_project` create, edit, and status use direct `INSERT`/`UPDATE`. |
| Form Builder | Direct MySQL | Form, field, layout, clone, publish, status, and reorder actions write directly to MySQL. |
| Task Management | MySQL-first | `project_task`, stages, responses, canvas, connections, and reorder actions commit to MySQL before Firebase sync. |
| Floor Management | Mixed/reverse order | Floor sort and status still update MySQL directly; some paths call Firebase before or after the MySQL update. |
| User Management | Active Firebase path plus legacy code | Firebase-first create/edit/status/password-reset paths exist, but old MySQL blocks remain in the source. |
| Group Management | Mostly Firebase-first | Active group writes use the Firebase writer; MySQL is still used for validation/read preparation. |
| Position Management | Mostly Firebase-first | Active position writes use the Firebase writer; projection and lifecycle evidence still need verification. |
| Bed Source | Firebase-first active path | Active save/status/reorder paths use Firebase; legacy helper functions remain in `app/foundation.php`. |
| Bed Treatment | Firebase-first active path | Active save/status/reorder paths use Firebase; legacy helper functions remain in `app/foundation.php`. |
| Roles and Permissions | Direct MySQL | Role, permission, and role-permission actions write directly to `builder_role`, `builder_permission`, and `builder_role_permission`. |
| System/Builder Settings | Mixed | Builder/system setup paths still contain direct MySQL writes; regular system-settings Firebase behavior must be kept separate from Builder configuration. |
| Login History | Firebase-first writer | History is written through the Firebase writer; MySQL is used for profile lookup/projection and needs lifecycle read-back proof. |
| Bed masterlist refresh | Approved exception | The external masterlist/API refresh may continue to update `project_bed` directly. |

## Exact high-priority locations

### User legacy paths

The active Firebase-first User path is followed by residual legacy MySQL code in `administrator/index.php`:

- `3818-3871`: direct `project_user` update/insert, avatar update, legacy relationship fields, and password-hash handling.
- `4021-4026`: direct `project_user.user_status` updates after the Firebase status path.
- `4126`: direct `project_user.user_password_hash` update after the Firebase password-reset path.

These blocks must be removed or structurally isolated before User cutover is declared clean. Password hashes and plaintext passwords must not be part of the Firebase projection.

### Task Management

The following Administrator actions call MySQL CRUD helpers and only afterward call Firebase synchronization:

- `administrator/index.php:2394-2537`: stage and stage-response create/update/delete.
- `administrator/index.php:2571-2650`: task create/update/delete.
- `app/foundation.php:7624-9150` (related task helpers): MySQL transactions for task, stage, response, canvas, connection, and ordering operations.

This is not Firebase-first. The required order is Firebase acknowledgement first, then TRAVERSE projection, then exact MySQL read-back.

### Floor Management

- `app/foundation.php:5493-5550`: floor sort order updates `project_building_floor` directly in MySQL.
- `app/foundation.php:5551-5589`: floor status updates `project_building_floor` directly in MySQL.

### Platform/Form/Access administration

Direct MySQL mutations remain in `administrator/index.php`:

- `2695-2750`: `builder_branch`.
- `2821-2870`: `builder_project`.
- `2931-3381`: `builder_form`, `builder_form_version`, `builder_form_field`, and `builder_form_layout`.
- `4438-4592`: `builder_role`, `builder_permission`, and `builder_role_permission`.

## Required cleanup gate

Before claiming the Administrator overhaul complete:

1. Enumerate every Administrator mutation action and its Firebase collection/table contract.
2. Convert each non-exempt mutation to Firebase-first with the real Firebase document ID mapped to its `*_key`.
3. Set `mysql_sync_status=PENDING` on every Firebase mutation and let TRAVERSE perform the projection.
4. Set synchronized metadata only after exact MySQL read-back.
5. Remove or permanently disable residual legacy MySQL mutation blocks and unused legacy helpers.
6. Verify tenant/project authorization, soft-delete/restore, duplicate handling, retry, and dead-letter behavior.
7. Run static checks plus live evidence: Firebase acknowledgement, TRAVERSE queue transition, MySQL projection, exact read-back, and visible browser result.

## Explicit exception

`resync_project_bed` / the Bed masterlist/API flow is intentionally excluded from this Firebase-first conversion. It is an external inventory source and may retain its approved direct MySQL update behavior.

## Task Builder implementation update (2026-08-27)

The active Task Builder mutation entry path now writes `project_task`,
`project_task_stage`, and `project_task_stage_response` to Firebase first,
uses the real Firestore document ID as the matching key, sets
`mysql_sync_status=PENDING`, and returns before the old MySQL CRUD branch can
run. Canvas, connection, and ordering actions use the same Firebase-first
writer. The three collections are now present in the TRAVERSE registry.

Static evidence passed:

- `php -l administrator/index.php`
- `php -l app/foundation.php`
- `node --check scripts/firebase-admin-task-write.mjs`
- `node --check scripts/firebase-mysql-sync/registry.mjs`
- `php tests/project-task-firebase-sync-static.php`
- `node tests/firebase-mysql-sync-master.test.mjs` — 44 tests passed
- `git diff --check`

Live Firebase acknowledgement, TRAVERSE queue transition, MySQL projection,
and exact read-back still require a live canary. The legacy MySQL helper code
is not called by the Administrator runtime. A source audit found the old task,
stage, response, canvas, connection, and ordering helpers are still referenced
by `tests/project-task-schema.php`, which is a legacy MySQL integration harness.
They are retained until that harness is migrated to the Firebase-first contract;
removing them now would break the schema test and obscure its remaining
coverage. The orphan `bx_admin_project_task_firebase_step` UI helper and the
manual Administrator task-sync artifact were removed.

## Current conclusion

Administrator is **not clean yet**. Task Builder active mutations are now
Firebase-first, but the remaining blockers are Floor Management, Form
Builder, Branch/Project Management, Roles/Permissions, residual User MySQL
code, and legacy Task Builder helpers/manual sync artifacts. No live data,
service restart, or deployment was performed.

## Incident record: synced Task Builder row missing from the view (2026-08-27)

### Root cause

TRAVERSE successfully projected the Firebase `project_task` document to MySQL
and acknowledged it, but the Administrator read helper still selected the
legacy unprefixed `created_at`/`updated_at` columns and ordered by `x_id`.
The repaired `project_task` projection table contains the Firebase lifecycle
fields instead, so the read query could fail even though the row was present
and `mysql_sync_status` was `SYNCED`.

### Resolution

The Task Builder read helpers now read `firebase_created_at` and
`firebase_updated_at`, exposing formatted aliases only for the existing UI
payload (`created_at` and `updated_at`). Ordering uses
`firebase_updated_at` and the authoritative `task_key`; no legacy persisted
date fields are recreated. Stage and response reads remain aligned with their
currently deployed schemas until their own Firebase projection contracts are
completed.

### Evidence

- MySQL contained the projected task with `mysql_sync_status = SYNCED`.
- The TRAVERSE journal contained `projection_processed` and
  `acknowledgement=acknowledged` for the task document.
- A direct read using the corrected fields returned the task row.
- PHP lint, the focused Firebase-first static test, frontend production build,
  and `git diff --check` passed.

### Prevention rule

When a projection schema is repaired, every Administrator read query must be
checked against the live column list. A successful Firebase acknowledgement or
MySQL row alone does not prove UI visibility; the read query and browser
payload must also be verified. Root cause, resolution, and evidence must be
recorded in this issue file for future TRAVERSE/schema changes.

## Incident record: fresh Task Builder reset recreated legacy tables (2026-08-27)

### Root cause

Dropping the three Task Builder projection tables did not leave MySQL empty:
loading `app/foundation.php` invokes the legacy `bx_ensure_project_task_schema`,
`bx_ensure_project_task_stage_schema`, and response schema helpers. Those
helpers recreate legacy table definitions (`x_id`, unprefixed timestamps, and
legacy metadata) independently of TRAVERSE. This is application bootstrap
behavior, not a Firebase projection acknowledgement.

### Current reset result

The exact Firebase collections `project_task`, `project_task_stage`, and
`project_task_stage_response` contain zero documents. The exact MySQL tables
were dropped and verified absent through a direct database connection that does
not load the application bootstrap. Target queue history was marked
`SUPERSEDED` with `test_reset_fresh_start`. TRAVERSE was restarted and is
`active`, loading all three allowlisted collections.

### Required follow-up

Before calling the Task Builder reset fully clean, remove or gate the legacy
schema bootstrap helpers and make the application tolerate an absent empty
projection table. TRAVERSE must remain the only creator of these projection
tables from the Firebase contract. Do not recreate the tables through a page
request, and do not claim a fresh schema until `SHOW CREATE TABLE` matches the
registry after the first Firebase document is projected.

### Resolution

The three application-side `bx_ensure_project_task*` schema paths are now
disabled; TRAVERSE source and service configuration were not changed. Task,
stage, and response list readers first tolerate an absent projection table and
return an empty list. Stage and response readers use
`firebase_created_at`/`firebase_updated_at` and their Firebase document keys,
so they do not require legacy `created_at`, `updated_at`, or `x_id` columns.

### Verification

- `php -l app/foundation.php` passed.
- The focused Task Firebase-first static test passed.
- `git diff --check` passed.
- Loading `app/foundation.php` after the reset did not recreate missing stage or
  response tables. TRAVERSE created an empty `project_task` table from its
  registry contract; it has `xId` plus Firebase-projected fields only.
- TRAVERSE remained active and its source was not modified.
