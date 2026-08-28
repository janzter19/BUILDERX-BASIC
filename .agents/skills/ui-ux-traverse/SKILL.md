---
name: ui-ux-traverse
description: Review and implement Administrator TRAVERSE UI, registry, pending-queue, and Firebase-to-MySQL status workflows with read-cost safeguards.
---

# UI-UX-TRAVERSE

Use this skill for any TRAVERSE page, registry form, pending-queue view,
restart/reload control, or UI that reports Firebase-to-MySQL synchronization.

## Non-negotiable contract

- TRAVERSE is a standalone server-side worker. Do not move its work into the
  browser or client Firebase SDK.
- Firebase is the source of truth. The web application does not write the
  MySQL projection directly for Firebase-first flows.
- `project_traverse_document` is an explicit collection registry containing
  only `xId`, `firebase_collection`, and `traverse_status`. It never stores a
  Firebase document ID. A collection must be both registered as `ACTIVE` and
  present in the compiled contract before monitoring.
- New Firebase documents must use their real Firestore document ID as the
  corresponding `*_key`, and must start with `mysql_sync_status = PENDING`.
- The worker listens only to PENDING documents in explicitly active
  collections, queues revisions, projects to MySQL, performs exact read-back,
  and acknowledges only after the read-back succeeds.
- New projection tables contain only the service field
  `xId INT(10) AUTO_INCREMENT PRIMARY KEY`; business columns come from the
  Firebase payload and contract. Never reintroduce legacy fields by habit.

## Read-cost safeguards

- Load the active collection registry once at startup.
- Use one filtered PENDING listener per active collection.
- Never add a timer or recurring full Firebase rescan. Worker polling may read
  the MySQL queue, but it must not poll Firebase.
- A listener's initial/reconnect snapshot and the exact current-document read
  for revision fencing are expected Firebase reads.
- Use a bounded recovery scan only after listener setup failure or an explicit
  operator recovery action.
- TRAVERSE status pages and Refresh controls must read MySQL operational tables
  only; display observed reads separately from provider billing totals.

## UI and diagnostics

- Show collection, document ID, queue ID, state, attempts, safe error code,
  error description, and updated time in the pending view.
- Keep registry and queue controls explicit, compact, and accessible. Use one
  clear confirmation for restart or destructive actions.
- Never display passwords, hashes, tokens, service-account details, or raw
  exceptions. Preserve sanitized technical error details in the operational
  log.
- If data is missing, check the MySQL registry, active status, PENDING marker,
  queue state, projection/read-back, and UI query separately. Do not claim
  success from a Firebase write or service status alone.

## Required references

Read the current TRAVERSE contract before changing code:

- `docs/traverse/README.md`
- `docs/project/firebase-mysql-sync-master.md`
- `scripts/firebase-mysql-sync/registry.mjs`
- `scripts/firebase-mysql-sync-master.mjs`

Run focused sync tests, syntax/build checks, and `git diff --check`. Do not
restart services, mutate live Firebase/MySQL data, or drop tables unless the
user separately authorizes that operation.
