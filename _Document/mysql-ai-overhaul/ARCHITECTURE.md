# BuilderX MySQL AI transport

BuilderX uses MySQL as the authoritative transport and lifecycle store for all Phase Builder and Phase Manager AI work.

## Runtime flow

1. The authenticated PHP action validates the requested workflow and persists its deterministic run and stage.
2. PHP saves the complete bounded context in `phase_builder_ai_context` and queues one stage job in `phase_builder_ai_job` inside a transaction.
3. The globally installed BuilderX VS Code companion receives the job key on loopback HTTP, validates the currently open workspace, and claims the job through the project-local PHP helper.
4. The helper reads the instruction, run request, stage request, and referenced contexts directly from MySQL and returns one complete prompt to the visible Codex Chat.
5. Codex submits the required JSON object as the direct helper command's final argument. The companion installs only the matching `complete` and `fail` Codex rules, and the helper transactionally saves the result to `phase_builder_ai_job.result_json` with direct read-back verification.
6. The browser reads progress and the result from MySQL through the authenticated PHP route. PHP validates the stage contract before any product-table write.

There is no context file, result file, request file, acknowledgement file, session-log watcher, project-bound service, or manually configured bridge path in this contract.

## Image rule

Image bytes are stored under `_Document/attachments/`. MySQL stores only the relative storage path, original name, MIME type, byte size, checksum, status, ownership, and timestamps. AI context JSON carries the same relative path metadata and never embeds a data URL.

## Ownership rule

The project is installed as a locally usable public-development workspace so Apache and the desktop owner can use the same copy without a custom Linux account, custom Linux group, ACL provisioning, or per-project systemd service.

## Installation rule

The browser installer creates the project, database, configuration, storage layout, and initial administrator. The BuilderX companion is installed once per VS Code profile and dynamically follows the currently open BuilderX workspace. A generated project requires no CLI command and no manual bridge path.

The browser installer does not create `.git` as Apache. The globally installed companion initializes missing Git metadata dynamically when the generated folder is opened in VS Code, so Codex receives a valid repository owned by the desktop user without an ownership repair or manual command.
