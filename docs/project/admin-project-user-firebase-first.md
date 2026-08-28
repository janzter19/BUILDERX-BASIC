# Administrator `project_user` Firebase-First Contract

## Scope and ownership

This document is the Administrator-side schema and migration contract for removing
authoritative group and position assignment from `project_user`. Administrator
writes must become Firebase-first. MySQL remains a synchronized projection and
read layer after RBMS-PORTAL task `01a03d35-6074-7c42-8ea9-025470ee9a54` verifies
the Master Sync dependency.

This document does not authorize Master Sync edits, live migrations, column drops,
data backfills, deployment, or live-data mutation.

## Current Administrator schema

The current `project_user` definition is in `app/foundation.php:3609-3647`.
`group_key CHAR(36) NULL` and `position_key CHAR(36) NULL` are legacy
assignment fields. Their indexes are `idx_project_user_group (group_key)` and
`idx_project_user_position (position_key)`.

The remaining user columns, in declared order, are:

1. `x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
2. `user_key VARCHAR(255) NOT NULL UNIQUE`
3. `project_key CHAR(36) NOT NULL`
4. `user_login VARCHAR(80) NOT NULL`
5. `user_auth_username VARCHAR(40) NULL`
6. `user_auth_email VARCHAR(190) NULL`
7. `user_password_hash VARCHAR(255) NOT NULL`
8. `user_name VARCHAR(160) NOT NULL`
9. `user_chat_name VARCHAR(160) NULL`
10. `user_email VARCHAR(190) NULL`
11. `user_mobile_number VARCHAR(40) NULL`
12. `user_avatar_path VARCHAR(500) NULL`
13. `user_avatar_original_name VARCHAR(255) NULL`
14. `user_avatar_mime_type VARCHAR(120) NULL`
15. `user_avatar_byte_size BIGINT UNSIGNED NULL`
16. `user_avatar_sha256 CHAR(64) NULL`
17. `user_avatar_uploaded_at TIMESTAMP NULL`
18. `user_status ENUM('DRAFT','ACTIVE','INACTIVE','LOCKED','DELETED') NOT NULL DEFAULT 'DRAFT'`
19. `user_failed_login_count INT UNSIGNED NOT NULL DEFAULT 0`
20. `user_password_changed_at TIMESTAMP NULL`
21. `user_last_login_at TIMESTAMP NULL`
22. `user_created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP`
23. `user_created_by_key CHAR(36) NULL`
24. `user_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
25. `user_updated_by_key CHAR(36) NULL`
26. `user_deleted_at TIMESTAMP NULL`
27. `user_deleted_by_key CHAR(36) NULL`

Existing indexes are the primary/unique keys, unique project-scoped login,
auth-username, email, and mobile indexes, plus
`idx_project_user_project (project_key)` and `idx_project_user_status
(user_status)`. The two assignment indexes must not be dropped until the
assignment migration has passed read-back and rollback acceptance.

## Assignment tables and legacy sources

`project_user_group` is defined at `app/foundation.php:3554-3572`. Its mapped
identity is `group_key`, with `project_key`, group metadata, and
`group_status ENUM('ACTIVE','INACTIVE','DELETED')`. Active membership is
currently represented indirectly by `project_user.group_key`, not by a separate
join table.

`project_user_position` is defined at `app/foundation.php:3588-3605`. Its mapped
identity is `position_key`; it has `project_key`, required `group_key`, position
metadata, status, and `idx_project_user_position_group (group_key)`.

The local source does not declare the schemas for legacy `project_group` or
`project_position`. It only conditionally copies these columns:

- `project_group` -> `project_user_group`: `group_key`, `project_key`,
  `group_name`, `group_description`, `group_status`, `created_at`, `updated_at`
  (`app/foundation.php:3697-3717`).
- `project_position` -> `project_user_position`: `position_key`, `project_key`,
  `group_key`, `position_code`, `position_name`, `position_description`,
  `position_status`, `created_at`, `updated_at`
  (`app/foundation.php:3720-3744`).

Their complete types, indexes, constraints, and ownership therefore require a
read-only schema inspection by the owning deployment/database authority before
any backfill or retirement plan is approved.

## Administrator dependency inventory

- User form state and controls: `frontend/src/App.tsx:6334-6365,
  6779-6808, 6887-6893`.
- User list joins and payload: `administrator/index.php:4945-4974`.
- User validation and direct assignment writes:
  `administrator/index.php:3525-3627,3643-3747`.
- User Firebase payload joins:
  `app/foundation.php:1389-1507`.
- User Firebase script fields:
  `scripts/firebase-user-sync.mjs:78-135`.
- Group member counts and assignment replacement:
  `administrator/index.php:4087-4098,4166-4169` and
  `administrator/index.php:5088-5090`.
- Position group validation and user-reference deletion guard:
  `administrator/index.php:3478-3496`.
- Task group authorization still reads `project_user_group` independently via
  `app/foundation.php:6506-6539`; it must continue to use stable group IDs.

## Target `project_user` Master Sync contract

Firebase collection: `project_user`.

MySQL projection: `project_user`.

Identity: Firestore document ID equals `user_key`. A new Firebase document must
allocate its real document ID first; that exact ID becomes the MySQL key. Existing
IDs must never be regenerated or remapped.

The Firebase document and synchronized projection contain the non-secret user
fields from the current schema, excluding `x_id`, `user_password_hash`,
`group_key`, and `position_key`, followed by these lifecycle fields:

- `mysql_created_at DATETIME NULL`
- `mysql_updated_at DATETIME NULL`
- `mysql_synced_at DATETIME NULL`
- `mysql_deleted_at DATETIME NULL`
- `mysql_sync_status ENUM('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING'`

`user_password_hash` must never be sent to Firebase. The Firebase profile
contract also declares `user_deleted_at` as a nullable timestamp so TRAVERSE
can create the corresponding nullable MySQL column even when new documents
initially contain `null`. Because the current MySQL
column is `NOT NULL` without a default, Portal must define an approved create
projection strategy before new-user projection can work. Firebase Auth remains
the credential authority; password reset must not expose password values or
hashes.

Every create or business update starts with `mysql_sync_status = PENDING`.
Soft-delete retains the Firebase document and sets `user_status = DELETED`,
`user_deleted_at`, and `mysql_deleted_at`; physical deletion is prohibited.
Master Sync may acknowledge only the same document revision, then sets Firebase
`mysql_sync_status = SYNCED` and `mysql_synced_at` and verifies the MySQL
projection read-back.

### Date and timestamp contract

The Create User writer owns mutation timestamps. It does not accept a PHP date
string as the authoritative Firebase time. `firebase_created_at` is written with
Firestore `FieldValue.serverTimestamp()` on creation and retained on edits;
`firebase_updated_at` is written with `FieldValue.serverTimestamp()` on every
mutation. Status-transition fields (`user_activated_at`, `user_deactivated_at`,
`user_locked_at`, and `user_deleted_at`) use the same Firestore server timestamp
when the transition occurs. The Firebase read-back must complete before success
is reported.

The `mysql_*` values are synchronization metadata only. They are UTC MySQL
`DATETIME(6)` strings such as `2026-08-27 10:55:13.649000`, with
`mysql_sync_status = PENDING` and `mysql_synced_at = NULL` until TRAVERSE
projects and acknowledges the document. No plain `created_at` or `updated_at`
fields are generated by the Create User Firebase writer.

## Assignment migration contract

Removing the two `project_user` columns is not safe until a replacement source
of truth is selected. The current application has no dedicated user-assignment
join table. The proposed minimum replacement is:

- `project_user_group/{group_key}.members[]` stores member references, or a
  separately allowlisted assignment collection is introduced.
- Each member reference uses the real `user_key` and `project_key`.
- Position references remain under `project_user_position` or move to an
  explicitly allowlisted assignment collection; they must not be inferred from
  a removed user column.
- Group and position documents retain their existing real IDs.

Portal must specify the exact collection/table mapping, field order, types,
lengths, nullability, defaults, indexes, soft-delete behavior, and transaction
boundary for this replacement before Administrator removes the legacy fields.

## Read-back and rollback requirements

The canonical projection read-back is:

```sql
SELECT user_key, project_key, user_login, user_auth_username,
       user_auth_email, user_name, user_chat_name, user_email,
       user_mobile_number, user_avatar_path, user_avatar_original_name,
       user_avatar_mime_type, user_avatar_byte_size, user_avatar_sha256,
       user_avatar_uploaded_at, user_status, user_failed_login_count,
       user_password_changed_at, user_last_login_at, user_created_at,
       user_created_by_key, user_updated_at, user_updated_by_key,
       user_deleted_at, user_deleted_by_key, mysql_created_at,
       mysql_updated_at, mysql_synced_at, mysql_deleted_at,
       mysql_sync_status
FROM project_user
WHERE user_key = ? AND project_key = ?
LIMIT 1;
```

Acceptance must prove Firebase acknowledgement, queue processing and retry
lifecycle, exact MySQL field read-back, project boundary enforcement,
Administrator authorization/CSRF, Firebase Auth outcome, and visible refreshed
UI state for create, edit, status, soft-delete, and reset-related flows.

The legacy columns and indexes remain during backfill. A rollback must restore
Administrator reads and assignment behavior from the preserved source without
changing Firebase document IDs. Column/index removal is a later, separately
approved migration only after assignment parity and rollback evidence pass.

## Portal handoff blocker

Requested by the user: RBMS-PORTAL task `01a03d35-6074-7c42-8ea9-025470ee9a54`
must verify and, if required, add Master Sync support for `project_user` and the
replacement assignment mapping. The current registry at
`scripts/firebase-mysql-sync/registry.mjs:17-23` allowlists neither
`project_user` nor an assignment collection. Administrator must not remove
direct MySQL writes, drop the legacy fields, or claim end-to-end completion
until Portal reports dependency readiness.

## Portal Firestore authorization boundary

Firebase Authentication UID is the authoritative web identity: `project_user/{userKey}`, `user_key`, `firebase_uid`, and the MySQL `user_key` must resolve to that same real Firebase UID. The signed-in profile is readable only when its `project_key` is in the refreshed `request.auth.token.projects` claim, its `user_status` is `ACTIVE`, `password_change_required` is boolean, and its document does not contain `group_key`, `position_key`, password hashes, or plaintext-password fields. Group, position, and `project_user_group` projections are readable only for an authorized project and active claimed group/position; inactive, locked, or deleted records are denied.

Client definition and assignment mutations remain explicitly denied. The current Portal custom-token issuer publishes `user_key`, `tenant_key`, `projects`, and `groups`, but no established Administrator authorization claim; rules must not invent a role fallback. The current Admin SDK `project_user` sync payload also still emits legacy `group_key` and `position_key`, so profile read access remains closed until that source-side projection is corrected. Exact mutation field allowlists, `firebase_collection`, `mysql_sync_status = PENDING`, and cross-document project/group/user/position reference checks require the approved Administrator claim and complete assignment schema before they can be safely enabled.

Claims for projects and groups must be derived from active `project_user_group` relationships and refreshed after assignment or status changes. Firestore rules cannot refresh an already issued ID token or observe Firebase Auth disabled/revoked state; the source-side lifecycle must update or revoke Auth accounts as appropriate, force token refresh or sign-out on the client, and deny `INACTIVE`, `LOCKED`, and `DELETED` profiles on subsequent reads. No claim-refresh or Auth-account-state guarantee is claimed by this rules-only change.
