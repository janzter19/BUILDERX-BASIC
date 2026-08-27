# Messenger Firebase-First Web Backend

**Decision date:** 2026-08-26  
**Status:** Web Firebase-first phase implemented; live Firebase verification and synchronization are deferred.

## Current-state findings

Before this phase, Messenger loaded messages from the PHP/MySQL endpoint and used PHP mutation actions for send, remove, and reactions. A client Firebase mirror was optional and could run after the PHP request, which allowed a false success when the mirror failed. Firestore rules only checked project/group membership and did not enforce direct-recipient or sender ownership. The existing background file is a legacy MySQL-to-Firebase queue worker and is not part of the active web mutation path.

## Target architecture

```text
Authenticated portal session
        | custom-token bootstrap
        v
Authenticated Firebase SDK
        +-- project_messenger_chat
        +-- project_messenger_chat_attachment
        `-- project_messenger_chat_reaction
```

The web Messenger reads the active conversation, attachments, and reactions from Firestore. It loads the active group member roster through the non-mutating PHP support action `messenger_load_members`. PHP does not persist Messenger messages, edits, removals, or reactions.

Firebase-to-MySQL synchronization, archive pruning, and read-receipt writes are outside this phase and must not be inferred from this implementation.

## Mutation contract

`project_messenger_chat` is the primary collection. Every Messenger collection touched by this phase carries:

- `mysql_created_at`
- `mysql_updated_at`
- `mysql_synced_at`
- `mysql_deleted_at`
- `mysql_sync_status`

New message and attachment writes set the created/updated values, set synced/deleted values to `null`, and set `mysql_sync_status` to `PENDING`. Edits preserve creation and sync metadata, update `mysql_updated_at`, and set `PENDING`. Soft removal preserves the message document, sets `message_status = REMOVED`, clears the displayed message text, records `removed_at`/`removed_by_user_key`, sets `mysql_deleted_at`, updates `mysql_updated_at`, and sets `PENDING`. No web path sets `mysql_sync_status = SYNCED`.

## Document identity

Every newly created Messenger chat, attachment, and reaction first allocates a real Firestore auto-ID with `doc(collection(...))`. Its `chat_key`, `attachment_key`, or `reaction_key` is copied directly from that reference's `.id`, and the same reference is used for the write. A later MySQL consumer must preserve that exact ID in its matching key column; it must not allocate a database sequence, UUID, hash, or reconstructed business ID.

Existing UUID- or composite-path Messenger documents remain supported: reads hydrate their key from the existing Firestore document ID, while edits and soft deletes reuse that document reference. Reaction toggles query by `chat_key` and `user_key`, choose the lexicographically first existing document as the canonical record, and update it only through rule-approved mutable fields so legacy metadata is preserved; remaining duplicate legacy records are soft-removed in the same batch. This provides deterministic one-reaction-per-user behavior, but simultaneous new-reaction writes in separate browser tabs can still race; the current rules protect ownership but cannot provide a unique cross-document constraint.

Messages with attachments use one Firestore batch. A failed commit does not clear the draft or pending attachment state. Reactions query the user's existing records for a message, reuse the deterministic canonical legacy document when present, and otherwise allocate a Firestore auto-ID. The same reaction toggles to `REMOVED`; another reaction replaces it. Edit and removal re-read the current Firebase document before writing to prevent stale ownership decisions.

## State machine and failure behavior

```text
DRAFT -> VALIDATING -> WRITING_FIREBASE -> CONFIRMED_FIREBASE -> VISIBLE
  ^          |                  |
  `----------+---- offline/error'
```

The client checks online state before send, edit, remove, and reaction operations. Firebase authentication, permission, validation, network, and batch failures show an error, retain the draft or edit state, and do not clear pending attachments or report success. Repeated sends are blocked while the current write is pending. Firebase batch commit is the confirmation boundary for a message and its attachments.

## Authorization model

Firestore rules require an authenticated user whose `request.auth.token.user_key` matches `request.auth.uid`, plus the claimed project and group. Group messages are readable by group members. Direct messages are readable only by the sender or direct recipient. New messages require the authenticated sender, valid conversation type, valid direct recipient scope, active status, and `PENDING` MySQL metadata. Only the message sender may edit or soft-remove a message. Attachment create/remove is tied to the parent message and sender. Reaction create/update is tied to the readable parent message and authenticated reaction owner. Physical delete is denied for messages, attachments, and reactions. Field allowlists reject unsupported client fields.

## Changed files

- `frontend/src/App.tsx`: Firebase message reads and pagination; direct batched send; direct edit, soft remove, and reaction writes; online/failure handling; minimal edit control.
- `index.php`: replaced the message-loading action with the non-mutating member-roster action and removed web send/remove/reaction mutation actions.
- `app/foundation.php`: added the MySQL metadata columns to active Messenger chat, attachment, read, and reaction schemas for the approved contract.
- `firestore.rules`: added tenant/group/direct ownership checks, field allowlists, metadata checks, sender edit/remove rules, attachment/reaction rules, and explicit physical-delete denial.
- `scripts/firebase-messenger-stream.mjs`: legacy queue output now leaves Messenger MySQL metadata `PENDING`; this worker is not used by the direct web path and was not deployed or restarted.
- `docs/project/messenger-sync.md`: recorded current state, target architecture, invariants, verification, and deferred work.

## Supported and unsupported collections

Supported in this phase: messages, image attachments, and reactions. `project_messenger_chat_read` has the standard metadata columns, but the current web UI has no read-receipt mutation operation, so no read-receipt writer was added. `project_messenger_chat_log` and archive/pruning remain deferred. The global metadata policy for unrelated RBMS collections is also deferred.

## Verification

Static checks run:

```text
php -l index.php                         passed
php -l app/foundation.php                passed
node --check scripts/firebase-messenger-stream.mjs   passed
npm run build (frontend)                  passed
git diff --check (touched files)          passed
```

Source assertions performed:

- No active frontend reference remains to `messenger_send_message`, `messenger_remove_message`, or `messenger_toggle_reaction`.
- The remaining Messenger PHP endpoints used by the frontend are non-mutating `messenger_load_members` and `messenger_stream_status`; Firebase custom-token bootstrap remains required authentication support.
- Direct send includes all five metadata fields and uses a single batch for the message and attachments.
- Direct edit, soft remove, and reaction writes set `mysql_sync_status = PENDING`.
- Firestore message, attachment, and reaction delete rules are explicitly false.
- Static assertions confirm newly created chat, attachment, and reaction keys come from their generated Firestore document references rather than a client UUID or composite path.

Not performed in this environment:

- No Firebase rules deployment.
- No live Firebase create/edit/soft-remove/reaction read-back.
- No emulator rules test because a Firebase emulator configuration was not available.
- No production service restart or queue deployment.
- No Firebase-to-MySQL synchronization or MySQL read-back; that is intentionally deferred.
- No live auto-ID create/edit/soft-remove/reaction read-back; the identity change has source/build evidence only.

## Follow-up work

The next phase must provide a separately approved Firebase-to-MySQL consumer with bounded cursors, idempotency, retry/dead-letter handling, metadata read-back, and explicit tests for mobile-originated writes. It must also decide how archived conversations and read receipts are exposed before any hot-table pruning is enabled.
