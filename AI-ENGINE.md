# BuilderX AI engine

## Authoritative transport

Phase Builder and Phase Manager communicate with the Planning and Coding
engines through MySQL:

```text
Browser -> PHP -> phase_builder_ai_context / phase_builder_ai_job
        -> VS Code companion -> visible Codex Chat
        -> tools/builderx-ai-job.php -> MySQL result
        -> PHP validation / transaction / read-back -> Browser
```

`phase_builder_ai_context` stores complete trusted context JSON and its binding
metadata. `phase_builder_ai_job` stores queue, claim, execution, result, failure,
and completion state. Existing persistent run, stage, chunk, event, and audit
tables remain the workflow ledger.

AI instructions and AI results are not exchanged through filesystem JSON files.
The companion receives a job key, dynamically uses the active VS Code
workspace, and calls the project helper without command-line database secrets.

Source recovery checkpoints remain physical private backups because they are
recovery artifacts, not an AI communication channel.

## Image storage

Uploaded images are stored below `_Document/attachments/`. Database records
contain only relative storage paths, metadata, SHA-256 hashes, ownership links,
and lifecycle state. Image bytes are never stored in MySQL.

## Required completion evidence

- MySQL context publish and exact read-back
- deterministic run/stage ordering
- job queue, claim, terminal result, and durable read-back
- server validation before persistence
- transaction commit plus application data read-back
- no project service or manual workspace path
- fresh installer database with zero inherited AI or narrative rows
