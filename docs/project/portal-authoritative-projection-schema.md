# Portal authoritative projection schema

This additive migration is owned by the Portal schema bootstrap in
`app/foundation.php`. It creates `project_group` and `project_position` with
Firestore document IDs as `*_key` primary keys (`VARCHAR(255)`), lifecycle
metadata, and project/status/sync indexes. It creates the new
`project_user_group` assignment contract only when that table name is unused.

The deployed schema currently uses `project_user_group` for the legacy group
definition. The bootstrap therefore leaves that table untouched; it does not
rename, drop, copy, or backfill rows. Moving the legacy definition to
`project_group` and claiming the assignment name requires an explicit,
backup-verified maintenance migration and read-back approval. Until then,
assignment Master Sync is gated rather than silently writing the legacy table.

`project_user` remains a profile projection. The migration adds nullable
`firebase_uid`, `password_change_required`, `user_disabled_at`,
`firebase_collection`, and `mysql_*` lifecycle columns plus lookup indexes.
The existing `user_key`, legacy `group_key`/`position_key`, and
`user_password_hash` column are retained temporarily for compatibility, but
the password hash is now nullable and is not a Firebase projection field. New Firebase Auth users must use
the Auth UID as both Firestore document ID and the approved `user_key` mapping;
the Firebase-compatible `VARCHAR(255)` key preserves the real Auth identity. No hash or
password belongs in the Firebase projection. The obsolete audit-owner columns
`user_created_by_key`, `user_updated_by_key`, and `user_deleted_by_key` are no
longer part of the `project_user` schema contract; the timestamp audit columns
remain.

All projected writes must use the Master Sync allowlist, revision checks,
idempotent upsert, soft-delete (`*_status = 'DELETED'` plus `mysql_deleted_at`),
and verified read-back. Schema creation itself performs no data mutation beyond
the additive DDL and is safe to rerun.
