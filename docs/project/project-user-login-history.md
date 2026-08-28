# Project user activity history

## Scope

Project user activity is recorded separately from Builder/Administrator login history. The project-level MySQL projection is `project_user_login_history`; its Firebase collection is also `project_user_login_history` and uses `user_login_history_key` as the document/table key.

The existing `builder_user_login_history` remains for Builder/Administrator authentication records.

## Firebase-first identity

Each new history record is created with `db.collection('project_user_login_history').doc()`.
That real Firestore document ID is copied to `user_login_history_key`; PHP does not generate
a UUID or insert the history row directly into MySQL. It starts with `mysql_sync_status =
'PENDING'` and `mysql_synced_at = null`, then Master Sync creates or updates the MySQL
projection after read-back validation.

Firebase lifecycle fields are `firebase_created_at`, `firebase_updated_at`, and
`firebase_deleted_at`, all written by the server as native Firestore timestamps. The event
timestamp `user_action_at` is also a server timestamp. Plain `created_at` and `updated_at`
are not emitted to Firebase and are not part of the Master Sync contract. Existing MySQL
columns with those legacy names remain only until an approved destructive migration removes
them; they are not authoritative and are not read by Firebase-first history writes.
For projection compatibility, those legacy MySQL columns are nullable; the sync worker does
not populate them.

The `project_user` path is `project_user/{firebase_auth_uid}` and both `user_key` and
`firebase_uid` equal that real document ID. Firebase lifecycle dates are native Firestore
`Timestamp` values in `firebase_created_at`, `firebase_updated_at`, and
`firebase_deleted_at`; the MySQL projection stores them as `DATETIME(6)`. New profile status fields use the required
`user_*` names: `user_password_change_required`, `user_disabled`, `user_deleted`, and
`user_locked`. Legacy unprefixed columns remain only as a guarded migration compatibility
layer and are not emitted by the Firebase writer.

## User snapshot metadata

`project_user` stores the latest known values:

- `user_last_login_at`, `user_last_login_ip_address`, `user_last_login_device`
- `user_last_logout_at`, `user_last_logout_ip_address`, `user_last_logout_device`
- `user_password_reset_at`, `user_activated_at`, `user_deactivated_at`, `user_locked_at`

The device value is a safe server-derived label such as `Chrome on Windows PC`, based on the browser user-agent. A browser cannot reliably provide the machine hostname or hardware name.

## History events

`project_user_login_history` records `LOGIN`, `LOGOUT`, `CREATE`, `EDIT`, `RESET_PASSWORD`, `ACTIVATE`, `DEACTIVATE`, `LOCK`, `DELETE`, and `RESTORE`, with action status, timestamp, previous/new status, actor, IP, and device. Passwords, hashes, Firebase tokens, and secrets are never stored.

Every new history row starts with `mysql_sync_status = 'PENDING'`. The Master Sync registry allowlists the collection for Firebase-to-MySQL projection and acknowledgement.

The verified login/logout hooks now write successful Administrator and Portal
events to Firebase first. Failed Firebase credential attempts also submit only
the username and safe Firebase error code to the history writer; passwords and
tokens are never included. The `project_user` snapshot is updated with the
latest login/logout time, IP, and derived device label through the Firebase
telemetry writer.

## Verification boundary

Schema bootstrap and static/build checks confirm the fields and query contract. A live Firebase acknowledgement, queue transition, and MySQL read-back require an authenticated action or login/logout event and a running sync service; those are reported separately from static validation.

## Administrator login prerequisite

Administrator login first resolves the friendly `user_login` through the
project-user profile. When `user_auth_email` is stale or unavailable, the
explicit Administrator `builder_user.firebase_uid` mapping is used to obtain
the actual Firebase Auth email through the Admin SDK. This prevents a valid
but unregistered profile email from causing `auth/invalid-credential`.

The browser submits the resolved Firebase email and password directly to
Firebase Auth. PHP receives only the resulting ID token and CSRF token. The
server verifies the token and Administrator mapping before creating the
Administrator session. Login-history writes must not be used as an
authentication dependency or as a source of password data.

This prerequisite was verified in the local browser on 2026-08-27: Firebase
authentication succeeded, the Administrator token handoff succeeded, and the
Users page rendered. A live login-history canary was also verified: Firebase
document acknowledgement, `PENDING` queue discovery, MySQL projection, exact
read-back, and final `SYNCED` metadata all succeeded. IP and derived device
labels were present without storing credentials or tokens.
