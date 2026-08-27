# Firebase-to-MySQL Messenger Prototype

## Status

This is a command-line prototype, not a systemd-managed production service.
Its entry point is:

```text
/var/www/html/rbmsv4/test.js
```

Run it manually from the RBMSv4 project directory:

```bash
cd /var/www/html/rbmsv4
node test.js
```

The prototype monitors the Firebase Firestore collection
`project_messenger_chat` and synchronizes matching documents to the MySQL
table with the same name.

## Objective

The prototype tests this flow:

1. Listen to Firebase documents where `mysql_sync_status == "PENDING"`.
2. Receive Firebase `ADDED` or `MODIFIED` changes.
3. Validate and upsert the message into MySQL using `chat_key`.
4. Mark the MySQL row as synchronized from Firebase.
5. Mark the Firebase document `mysql_sync_status = "SYNCED"`.

```text
Firebase: project_messenger_chat
        │
        │ onSnapshot where mysql_sync_status == PENDING
        ▼
test.js
        │
        │ prepared INSERT ... ON DUPLICATE KEY UPDATE
        ▼
MySQL: project_messenger_chat
        │
        │ successful MySQL write
        ▼
Firebase document: mysql_sync_status = SYNCED
```

The listener is Firebase realtime monitoring. It does not poll Firebase and
does not read the MySQL table to discover Firebase changes.

## Collection filter

The listener uses this Firestore query:

```js
db.collection('project_messenger_chat')
  .where('mysql_sync_status', '==', 'PENDING')
```

Only documents currently marked `PENDING` are included in the listener. If
there are 1,000 documents but only one is `PENDING`, the initial snapshot
contains only that one matching document.

The filter applies to document membership, not to individual event types.
Consequently, a document can produce a `REMOVED` change when it changes from
`PENDING` to `SYNCED`, even though the Firebase document was not deleted.

## Initial snapshot behavior

The first snapshot establishes a baseline. Its matching documents are counted
and reported as `baseline_ready`, but they are not processed as `ADDED` events.

Example:

```text
{"ok":true,"status":"baseline_ready","collection":"project_messenger_chat","documents":1}
```

To trigger processing during a test, change a document to `PENDING` after the
listener is running. A document that was outside the query and then enters it
appears as `ADDED`.

For a future production synchronizer, existing `PENDING` documents should be
processed on startup rather than silently treated only as a baseline.

## Event behavior

### ADDED

`ADDED` means a document entered the `PENDING` query. This commonly happens
when a document is changed from `SYNCED` to `PENDING`.

The prototype:

1. Reads the document data.
2. Uses the Firebase document ID as a fallback for `chat_key`.
3. Validates required fields.
4. Normalizes timestamps for MySQL.
5. Upserts the message into MySQL.
6. Marks the Firebase document `SYNCED` if it is still the same pending
   version.

### MODIFIED

`MODIFIED` means a document already in the `PENDING` query changed. The same
validation, MySQL upsert, and Firebase acknowledgement flow is used.

If a document is already `SYNCED`, editing its content without also setting
`mysql_sync_status` back to `PENDING` will not trigger this filtered listener.

### REMOVED

`REMOVED` has two possible meanings with this filtered query:

1. The document still exists in Firebase but no longer matches the query,
   normally because the prototype changed its status to `SYNCED`.
2. The Firebase document was physically deleted.

The prototype checks document existence:

- Existing document: logs `REMOVED` with reason
  `document_left_pending_filter` and does not write MySQL again.
- Deleted document: uses the document ID as `chat_key` and soft-removes the
  existing MySQL row by setting `message_status = 'REMOVED'`.

A physically deleted Firebase document has no complete message payload, so a
full MySQL upsert is not possible at that point. If no MySQL row exists, no row
is inserted.

## Firebase-to-MySQL processing

### Required Firebase fields

The following values are required for an `ADDED` or `MODIFIED` upsert:

```text
chat_key       or a non-empty Firebase document ID
project_key
group_key
sender_user_key
sender_name
```

Missing required values are logged as an error and the MySQL write is not
attempted.

### Field mapping

| Firebase value | MySQL column | Notes |
|---|---|---|
| `chat_key` or document ID | `chat_key` | Stable unique upsert key |
| `project_key` | `project_key` | Required |
| `group_key` | `group_key` | Required |
| `conversation_type` | `conversation_type` | `group` or `direct`; defaults to `group` |
| `direct_recipient_user_key` | `direct_recipient_user_key` | Optional |
| `reply_to_chat_key` | `reply_to_chat_key` | Optional |
| `sender_user_key` | `sender_user_key` | Required |
| `sender_name` | `sender_name` | Required |
| `message_text` | `message_text` | Optional; limited to 8,000 characters |
| `message_type` | `message_type` | `text`, `image`, or `mixed` |
| `message_status` | `message_status` | `ACTIVE` or `REMOVED` |
| `removed_at` | `removed_at` | Optional timestamp |
| `removed_by_user_key` | `removed_by_user_key` | Optional |
| collection name | `firebase_collection` | Set to `project_messenger_chat` |
| generated current time | `firebase_synced_at` | Set by MySQL on success |
| normalized `created_at` | `created_at` | MySQL timestamp-compatible text |
| normalized `updated_at` | `updated_at` | MySQL timestamp-compatible text |

The prototype synchronizes the chat table only. It does not synchronize
attachments, read receipts, reactions, or the MySQL sync-event queue.

## MySQL upsert design

The table uses:

```text
x_id      PRIMARY KEY, AUTO_INCREMENT
chat_key  UNIQUE KEY
```

`x_id` is not supplied by the prototype. `chat_key` is the idempotent key used
by `INSERT ... ON DUPLICATE KEY UPDATE`, so repeating the same Firebase event
updates the existing MySQL row instead of creating a duplicate message.

The successful inbound write sets:

```text
firebase_sync_status = 'SYNCED'
firebase_synced_at   = CURRENT_TIMESTAMP
```

This indicates that the MySQL row has been accepted from Firebase. The
prototype does not enqueue a separate MySQL-to-Firebase event.

## Timestamp handling

Firebase may provide timestamps such as:

```text
2026-08-26T08:16:39.641Z
```

MySQL timestamp columns require a compatible value such as:

```text
2026-08-26 08:16:39
```

`test.js` converts Firebase `Timestamp` values, JavaScript dates, and ISO-8601
strings before sending them to MySQL. This avoids errors such as:

```text
Incorrect datetime value: '2026-08-26T08:16:39.641Z'
```

## Firebase acknowledgement safety

After the MySQL upsert, the prototype uses a Firestore transaction before
setting `mysql_sync_status = 'SYNCED'`. It checks that:

- the Firebase document still exists;
- its current status is still `PENDING`; and
- its Firestore update time has not changed since the event was received.

If the document changed while it was being processed, the acknowledgement is
not applied to that newer version. The MySQL and Firebase operations are still
separate systems and are not one distributed transaction.

## Configuration

`test.js` loads `.env` from the project directory.

### Firebase settings

Required:

```dotenv
FIREBASE_PROJECT_ID=your-project-id
GOOGLE_APPLICATION_CREDENTIALS=/secure/path/service-account.json
```

`FIREBASE_SERVICE_ACCOUNT_PATH` may be used instead of
`GOOGLE_APPLICATION_CREDENTIALS`.

Optional:

```dotenv
FIREBASE_MESSENGER_COLLECTION=project_messenger_chat
```

The service-account JSON must remain outside the web root and outside version
control. Do not print its contents or put credentials into this document.

### MySQL settings

The following environment variables override the project configuration when
present:

```dotenv
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=rbmsv4
DB_USERNAME=database-user
DB_PASSWORD=database-password
```

When these values are not in `.env`, the prototype reads the existing project
database configuration from:

```text
phases/config.local.php
```

Credential values are never printed by the prototype.

## Console output

Every log is JSON followed by an 80-character divider.

Startup example:

```text
{"ok":true,"status":"monitoring","collection":"project_messenger_chat","source":"firestore_onSnapshot","filter":{"field":"mysql_sync_status","operator":"==","value":"PENDING"},"mysql_table":"project_messenger_chat"}
--------------------------------------------------------------------------------
{"ok":true,"status":"baseline_ready","collection":"project_messenger_chat","documents":0}
--------------------------------------------------------------------------------
```

Successful synchronization includes fields similar to:

```text
{"ok":true,"collection":"project_messenger_chat","event":"MODIFIED","key":"chat-key","mysql_upserted":true,"firebase_status":"synced"}
--------------------------------------------------------------------------------
```

Errors are written to standard error and also receive the divider. The
prototype does not expose database passwords or service-account contents.

## Manual test procedure

1. Start the prototype:

   ```bash
   cd /var/www/html/rbmsv4
   node test.js
   ```

2. Confirm the `monitoring` and `baseline_ready` messages.

3. In Firebase Console, select a valid `project_messenger_chat` document.

4. Set `mysql_sync_status` to `PENDING` and save it.

5. Confirm an `ADDED` event, followed by a MySQL upsert and Firebase status
   acknowledgement.

6. While the document is `PENDING`, edit a mapped field such as `message_text`
   and save it. Confirm a `MODIFIED` event and read back the MySQL row.

7. To test another edit after synchronization, set the document back to
   `PENDING`, then change the mapped field.

8. Observe the `REMOVED` event generated when the successful acknowledgement
   changes the document from `PENDING` to `SYNCED`. It should be logged as
   `document_left_pending_filter` and should not cause a second MySQL upsert.

9. Stop the prototype with `Ctrl+C`. It should unsubscribe from Firestore,
   wait for queued work, close MySQL, and print a `stopped` message.

### MySQL read-back example

Use a safe read-back that does not display message text or credentials when
possible:

```sql
SELECT
  chat_key,
  project_key,
  group_key,
  message_status,
  firebase_sync_status,
  firebase_synced_at,
  updated_at
FROM project_messenger_chat
WHERE chat_key = 'the-chat-key';
```

Expected successful inbound state:

```text
firebase_sync_status = SYNCED
firebase_synced_at   = populated
```

## Failure behavior

### Startup failures

The process exits if it cannot load required Firebase settings, database
credentials, or connect to the MySQL table.

### Processing failures

If validation, MySQL upsert, or Firebase acknowledgement fails:

- an error is written to standard error;
- the event is not reported as successful;
- the Firebase document is not forcibly changed to `SYNCED`.

The prototype does not yet implement durable retry, exponential backoff,
dead-letter storage, or a `FAILED` Firebase status. A failed pending document
may require another edit, a process restart, or a future retry mechanism before
it is processed again.

## Important limitations

- `test.js` is not installed as a systemd service.
- The initial matching snapshot is not processed.
- Only `project_messenger_chat` is synchronized.
- Attachments, reactions, read receipts, and chat logs are outside this
  prototype.
- A filtered listener cannot observe changes to documents that are not
  `PENDING`.
- A filtered-listener `REMOVED` event is not automatically a physical delete.
- A physical Firebase delete contains no full payload for a complete upsert.
- Actual Firebase deletion is handled as a MySQL soft-removal when the deleted
  document was visible to the pending query.
- There is no distributed worker lock or cross-process claim mechanism.
- MySQL and Firestore writes are not one atomic distributed transaction.
- The prototype logs document data for test visibility; production logging
  should minimize message and personal data exposure.
- Direct Firebase writers must set `mysql_sync_status = 'PENDING'` for every
  change that needs MySQL synchronization.

## Production-readiness work still required

Before converting this prototype into a background service, define and verify:

1. Startup processing for existing pending documents.
2. Durable retry and failure state.
3. Duplicate claims and multi-process protection.
4. Version or conflict handling for simultaneous Firebase and MySQL changes.
5. Attachment, reaction, and read-receipt synchronization boundaries.
6. Secure, minimized operational logging.
7. Service supervision, restart behavior, and deployment configuration.
8. Committed MySQL read-back and Firebase acknowledgement evidence.
9. A deliberate policy for physical deletes versus soft-delete tombstones.

## Acceptance checklist for this prototype

- [ ] `node test.js` starts from `/var/www/html/rbmsv4`.
- [ ] The listener reports the `PENDING` filter.
- [ ] The initial snapshot count contains only matching documents.
- [ ] A document entering `PENDING` produces `ADDED`.
- [ ] A pending document edit produces `MODIFIED`.
- [ ] MySQL contains the same `chat_key` after the upsert.
- [ ] MySQL `firebase_sync_status` becomes `SYNCED`.
- [ ] Firebase `mysql_sync_status` becomes `SYNCED` after MySQL success.
- [ ] The acknowledgement-generated `REMOVED` is ignored for MySQL writes.
- [ ] Timestamp values are accepted by MySQL.
- [ ] Ctrl+C closes the listener and MySQL connection cleanly.
