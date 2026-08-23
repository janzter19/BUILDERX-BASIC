# BuilderX Two-Engine AI Overhaul

Status: MySQL-native Phase Builder/Phase Manager transport and browser-only project installation are implemented in Developer and passing focused source/database/build gates; official installer publication, a genuine fresh database installation, and live visible-Codex delivery remain release gates
Authoritative source: `/var/www/html/developer`
Last updated: 2026-08-23

## Purpose

This document preserves the agreed direction for the major BuilderX AI overhaul. It is the source of truth for future implementation decisions so the work is not reconstructed from chat history or partially remembered requirements.

The overhaul must provide:

- one general-purpose Phase Builder planning engine;
- one general-purpose Phase Manager coding engine;
- deterministic multi-stage and semantic-chunk orchestration;
- persistent run, stage, chunk, and event state;
- MySQL-native web-to-AI and AI-to-web exchange with validated JSON stored in database rows;
- installation isolation and automatic current-project resolution;
- one Sharingan capture and annotation surface available directly within the User Portal, Administrator Portal, and Phases routes through the shared Bridge lifecycle;
- no additional autonomous AI-agent dispatch.

## Implementation progress

### Milestone 1 — persistent run foundation

Independently re-verified and repaired on 2026-08-20 in the authoritative Developer source:

- added the database-backed `phase_builder_ai_run`, `phase_builder_ai_run_stage`, `phase_builder_ai_run_chunk`, and `phase_builder_ai_run_event` tables;
- added one reusable run store for the Planning and Coding engine architecture, with an enforced workflow-to-engine boundary, project identity, conflict-safe idempotent start, strict stage transitions and ordering, exact statuses and errors, bounded payloads, attempt limits, transactions, audit events, and direct read-back;
- connected the existing Narrative & Cleanup workflow as a compatibility pilot so it creates a persistent run before bridge work, saves each stage result, resumes successful checkpoints, retries a failed stage, and exposes persisted state after reload;
- changed the Phase runtime filesystem root to derive from the executing installation's canonical directory;
- verified that executable source and compiled frontend assets contain no absolute Developer path;
- fixed the independent-audit defects: missing chunk read-back, unchecked audit and commit results, incomplete transaction-local read-back, permissive status transitions, unenforced retry limits, stale terminal timestamps during retry, runtime repair touching the Sharingan directory, and hardcoded Developer paths in deploy/service templates;
- verified idempotent start, idempotency conflict rejection, workflow-to-engine rejection, out-of-order and invalid-transition rejection, validating state, failed-stage retry, retry exhaustion, cancellation, expiration, terminal success, terminal mutation rejection, cross-project rejection, authorization rejection, CSRF rejection, frontend build, clean executable/compiled path scan, and cleanup with disposable test records;
- reconciled `docs/project/rules.md` with the approved AI Bridge, Codex AI Chat, two-engine, no-extra-agent, and amended Sharingan surface-feature decisions.

The foundation is now reused by every migrated Planning and Coding workflow. Reload recovery resumes a saved RUNNING Bridge request ID instead of dispatching a duplicate request, and the Bridge persists handoff metadata so a service restart can restore result tracking for the current project.

### Milestone 2 — shared AI Bridge adapter and deterministic orchestrator

Implemented and independently verified on 2026-08-20 in the authoritative Developer source:

- added one authenticated, loopback-only, project-local, server-owned BuilderX AI Bridge adapter designed as the shared transport for both engine types, with current-installation workspace verification, active-chat readiness, bounded delivery, progress, result retrieval, timeouts, exact failures, and no provider fallback;
- separated the canonical project identity from a dynamically derived and canonically verified web/IDE workspace alias so bind mounts and symlink-equivalent paths remain isolated without embedding a checkout path;
- persisted the Bridge request ID, BuilderX Bridge provider identity, and model identity only when the Bridge exposes it, with transaction-local stage, chunk, event, audit, and direct read-back checks;
- added a deterministic server orchestrator that selects the first incomplete persisted stage/chunk, validates source hashes and strict stage results, enforces retry and between-stage cancellation, resumes running Bridge requests, and never repeats a verified successful checkpoint;
- migrated the Narrative & Cleanup compatibility pilot to authenticated server dispatch, server-bound progress/result reads, and deterministic transition actions; its browser code no longer calls the loopback Bridge directly;
- verified a real active Codex AI Chat result-file handoff through Bridge 1.5.1, including acknowledgement, exact JSON result, provider request binding, database read-back, and disposable-record cleanup;
- repaired the live Bridge upgrade path on 2026-08-22 with Bridge 1.5.2: the user service is now a rendered regular file bound to `rbmsv6`, the known unresolved-template symlink is preserved in a private backup, upgrades restart the already-active service, health distinguishes installed and active companion versions, and handoff delivery canonicalizes the project identity before using VS Code's verified `/var/www/...` workspace alias. A live `Hi` diagnostic was acknowledged, completed, read back, and left health `ready_to_send: true` without weakening cross-project rejection;
- changed installer preflight presentation on 2026-08-22 so Git alignment and strict release-evidence failures remain visible maintenance warnings but never disable the review action or stop the POST before installation begins. Project creation still enforces CSRF, field rules, new-folder and new-database requirements, transactional administrator/audit read-back, lifecycle verification, and complete folder/database cleanup after an actual failure;
- preserved Sharingan as a non-engine surface feature during the shared-core pilot. The former leave-unchanged decision is superseded by the amended three-surface integration contract below, so its direct User Portal, Administrator Portal, and Phases integration remains pending until separately verified.

The shared core now serves all migrated Planning and Coding workflows. Anonymous and authenticated non-administrator sessions are denied access to persistent AI runs and the general Bridge proxy; authenticated administrators pass the allow path. The legacy Narrative coordinator route now also requires a valid Administrator session and CSRF token.

### Milestone 3 — Requirements Analysis semantic chunks

Requirements Analysis is implemented and independently re-verified on 2026-08-21 in the authoritative Developer source as a bounded Planning Engine workflow:

- migrated Requirements Analysis to the persistent Planning Engine with context, exactly nine sequential semantic chunks, deterministic merge, bounded integration review, and transactional persistence/read-back checkpoints;
- split the nine chunks by actors and roles, functional requirements, User Portal, Administrator Portal, Android application, database and synchronization, security and permissions, validation and recovery, and deployment and operations;
- bound every run to the authenticated owner, current installation, Phases route, saved Narrative draft, immutable source hash, request version, and resumable checkpoint state;
- added strict PHP validation for every chunk, deterministic duplicate-ID rejection, immutable requirement IDs, traceability, merge hashes, review hashes, stale-source rejection, and source-identical successful-run reuse;
- verified 13 persisted stages/chunks in total, nine semantic chunks, deterministic merge, tamper rejection, integration review, immutable IDs, source cache reuse, stale-source rejection, and terminal `SUCCEEDED` read-back;
- preserved the Planning Engine boundary: model output cannot edit source files or directly mutate product tables.

### Sharingan — shared User, Administrator, and Phases surface

Implemented and independently verified on 2026-08-21 in the authoritative Developer source:

- integrated the same Sharingan toggle, selection, annotation, capture, context, and report surface into authenticated User Portal, Administrator Portal, and Phases rendering without introducing another engine;
- routed all three surfaces through one authenticated, CSRF-protected, server-owned endpoint, the shared Planning Engine, and the shared BuilderX AI Bridge adapter; Sharingan browser code no longer calls the loopback Bridge directly;
- bound context, route, owner, installed-project identity, run, stage, source hash, and provider request ID before accepting progress, result, or persistence actions;
- stripped sensitive selected-element attributes and field values, bounded and validated images, stored captured files under current-project permissions, and required Administrator approval on every proposed change;
- changed Bridge result handling so a complete JSON result file finishes polling and event delivery without waiting for unrelated conversational wrap-up, while retaining the shared single-chat busy gate between requests;
- moved Sharingan GET-action CSRF validation to an explicit request header so tokens do not appear in result or event URLs;
- verified the real capture → context → Bridge acknowledgement → result → validation → persistence/read-back lifecycle on all three surfaces with `SUCCEEDED` Planning runs, no product mutation, no added engine, and disposable artifact cleanup;
- verified anonymous, authenticated non-administrator, route-mismatch, and invalid-CSRF allow/deny paths, plus mobile-width anonymous control hiding on all three routes;
- initially left authenticated desktop/mobile visual click-through acceptance open because no browser password was transmitted during that verification pass. The later authenticated three-surface continuation below closed this visual acceptance gap with privacy-safe captures, persistent lifecycle read-back, reload restoration, and responsive evidence.

### Milestone 4 — remaining Planning Engine workflows

Implemented and independently verified on 2026-08-21 in the authoritative Developer source:

- migrated System Architecture, UI/UX Design, Execution Roadmap, todo-chat consolidation, and the Bridge diagnostic to the shared persistent run store and server-owned adapter;
- added versioned result contracts, immutable source hashes, deterministic stage order, semantic chunk identities, integration reviews, transaction/audit/read-back persistence, and exact terminal statuses;
- removed autonomous orchestration controls and runtime MCP paths without introducing a replacement engine or hidden provider;
- verified that production browser assets contain the two approved engines and no loopback Bridge URL, autonomous fan-out route, Codex CLI dependency, or alternate-provider control.

### Milestone 5 — Phase Manager Coding Engine

The automated Coding runtime is implemented and independently re-verified on 2026-08-21 in the authoritative Developer source; the dedicated operating-system identity and release gates remain open:

- added authenticated todo execution and rollback workflows bound to the saved draft, task, sub-task, todo, current project, server-created context record, and execution log;
- added deterministic inspection, plan, implementation, verification, evidence, optional Git Update, and persistence checkpoints using the same Bridge adapter as the Planning Engine;
- added a server-created, hash-bound source checkpoint after the read-only inspection and plan stages and before implementation dispatch. It snapshots eligible current-project source into `storage/backups/phase-ai-source` with cross-process directory mode `0777` and file mode `0666`, so Apache and the desktop owner can use the same fresh install without accounts, groups, ACLs, or manual ownership repair. Integrity remains bound to the database-held manifest SHA-256; symbolic links are rejected, and `.git`, BuilderX runtime state, dependencies, generated output, local configuration, key material, database dumps, logs, caches, uploads, and other unsafe files are excluded;
- bound the same checkpoint key, execution key, run key, project identity, policy, manifest path, file count, byte count, and SHA-256 to both the saved execution context and persistent run request. The server verifies the hash-bound manifest and every snapshotted file before implementation dispatch, implementation completion, verification, evidence, optional Git Update, execution-log persistence, and final run persistence;
- changed the Coding result contract so a completed result must reference the exact server-created checkpoint key and manifest hash. Missing, forged, unpersisted, cross-process-incompatible, or tampered checkpoint evidence is rejected independently of model self-reporting;
- explicitly limited this checkpoint to source-file recovery. It does not protect database rollback, and a Coding result with completed database changes is rejected until a separate server-created and verified database recovery mechanism is implemented;
- retained focused test evidence before completion, strict result validation, transaction/audit/read-back persistence, attributable rollback evidence, and the requirement to preserve unrelated user changes;
- made the Git Update stage skip safely without explicit local approval and prohibit remote operations inside the normal todo workflow;
- repaired todo chat, consolidation, approval, execution, and rollback database writes so checked transaction boundaries, parameterized queries, audit events, direct read-back, and server rehydration are enforced;
- verified Planning-versus-Coding engine and route boundaries, server snapshot exclusions and private modes, manifest and file tamper rejection, execution-context/run-request binding and direct read-back, missing/forged checkpoint rejection, unprotected database-completion rejection, Git-without-approval skip, reload reuse of the existing Bridge request, and successful source execution/rollback terminal state.
- derived the final Coding status on the server from the persisted inspection, plan, implementation, verification, evidence, and persistence checkpoints. Non-Git checkpoints cannot be skipped, evidence must be non-empty, and a client-reported persistence status that disagrees with the derived result is rejected.

### 2026-08-23 MySQL transport and browser-only installer simplification

This milestone supersedes the earlier Coordinator file-exchange, per-project user-service, shared-group, ACL, and manual workspace-path design:

- added `phase_builder_ai_context` and `phase_builder_ai_job` as the canonical transport for every Phase Builder and Phase Manager Planning/Coding request, prompt, progress event, validated result, failure, and read-back identity;
- added checked ADODB transaction, parameterized write, audit, SHA-256, owner/project binding, and durable read-back handling for the context and job rows;
- changed the server orchestrator to enqueue a MySQL job key and changed the BuilderX VS Code companion to claim and complete that job through the installed project's own database configuration. No request, context, or result JSON file is used for transport;
- changed the companion into one versioned VS Code extension that hosts its loopback endpoint and follows the active VS Code workspace. Installed projects no longer create a systemd user service, edit a service file, save a manual bridge path, or run a project-specific setup command;
- removed the obsolete Node bridge service, user-service installer, service unit, permission finalizer, and ACL provisioner from source and the release contract;
- created `_Document/attachments/todo-chat` and `_Document/attachments/sharingan` for physical images. MySQL stores only normalized project-relative path, MIME type, byte size, SHA-256, status, and relationships; the former image `data_url` column is migrated away and dropped;
- changed Phase runtime readiness into a disposable MySQL publication/read-back/removal test instead of a filesystem ownership repair;
- simplified the web installer to one browser workflow. It creates an empty database and self-contained project, prepares ordinary runtime directories, exposes the checksum-bound companion download, and requires no post-install CLI, account/group/ACL setup, service restart, or workspace override;
- added fresh-install rejection for inherited Phase Builder narrative, AI job/context/run, roadmap, todo, attachment, and generated runtime data;
- verified PHP/shell/JavaScript syntax, MySQL transport lifecycle, deterministic orchestrator, all Planning workflows, Coding execution/rollback, Sharingan on three surfaces, Phase Manager schema/export, authorization, source recovery, installer static/package gates, and the frontend lint/build. A live visible-Codex handoff and genuine fresh release installation remain required before declaring the release complete.

### Release and installation gate

Implemented in source and installer tooling on 2026-08-21, but not yet eligible for a clean release-manifest refresh:

- added different-name installation isolation checks covering runtime source references, compiled assets, symlink containment, generated runtime state, AI/todo database rows, and forbidden source paths stored in the installed database;
- added a source-unavailable lifecycle harness that renders the installed User Portal and Phases entrypoints under project-only PHP `open_basedir`, starts the installed Bridge worker on an isolated port, verifies its current-project workspace, and optionally checks the real Apache routes;
- hardened the installer to reject symbolic links, require the two-engine runtime files and production markers, create directional Coordinator context permissions, and run the fresh-state plus web/worker lifecycle checks before reporting installation success;
- reconciled the Phase Manager bootstrap schema with the live task/checklist contract so a fresh database creates the current status, scope, checklist text, completion, and ordering columns; added transactional insert, update, audit, direct read-back, rollback, and legacy-column-absence verification;
- verified the isolation and source-unavailable web/worker harnesses against a disposable different-name copy;
- re-ran the different-name executable/runtime scan with 317 files inspected, no symlinks, and zero generated runtime files, then rendered the installed User Portal and Phases under project-only `open_basedir` and verified the isolated Bridge worker resolved only the disposable workspace. The disposable copies were moved to trash;
- kept the fresh-database claim open: the disposable source-unavailable copy intentionally reused Developer database configuration for render coverage, and the blanket database path scan found one expected P3 checklist prose reference to `/var/www/html/developer`, not a runtime/configuration dependency. A real fresh database under a different installation path is still required;
- retained the clean-Git source/template alignment gate. The current Developer worktree is intentionally uncommitted. Verified shared payload files have been mirrored into the working installer template for acceptance, but the installer source manifest remains bound to its previous clean revision; no clean release refresh or post-change fresh release installation has been claimed.

### 2026-08-21 continuation verification and P3 read-back

- passed `tests/phase-ai-source-checkpoint.php`, including safe-source selection, runtime/dependency/generated/local-secret exclusion, private permissions, manifest hashing, file tamper rejection, the explicit no-database-protection boundary, and disposable checkpoint cleanup;
- re-passed the persistent run lifecycle, orchestrator, Requirements Analysis, Sharingan three-surface workflow, shared Planning/Coding workflow, Sharingan HTTP authorization, and Phase Manager schema suites;
- passed PHP lint for every changed or new PHP runtime/test file and `/var/www/html/_installer/index.php`, `node --check tools/builderx-bridge/server.mjs`, frontend lint with the existing warnings, and the production build. The compiled JavaScript is `frontend/dist/assets/index-B8BozRGa.js`;
- scanned executable runtime plus source and compiled browser assets: no browser loopback Bridge URL, direct OpenAI or alternate-provider API, runtime MCP actor, Codex CLI invocation, hidden provider, or third active engine was found;
- updated 26 evidence-supported P3 checklist items in one transaction with audit events and direct read-back. P3 remains `In Progress`: P3-T01 is `4/5`, P3-T02 through P3-T06 are `5/5`, P3-T07 is `4/5`, P3-T08 is `4/5`, P3-T09 is `2/5`, and P3-T10 is `3/5`;
- left the unsupported checklist items pending: fresh empty database installation, a dedicated Coding operating-system identity boundary, full deployed-copy no-cross-installation proof, clean-template/fresh-release lifecycle, authenticated three-surface visual interaction, and responsive visual acceptance.

### 2026-08-21 Milestone 5 conflict-gate continuation

- re-read this complete contract, inventoried the full dirty worktree and untracked implementation, found no Git operation in progress, confirmed one live Bridge worker and zero active database AI runs, and preserved all pre-existing changes;
- repaired persistent-run worker ownership with deterministic worker IDs, bounded leases, automatic `STAGE_TIMEOUT` expiration, terminal lease cleanup, direct read-back, and rejection of a duplicate running-stage claim before a Bridge request is persisted;
- moved durable Bridge handoff state from a shared temporary directory into project-local `.builderx/runtime/bridge-state`, enforced directory mode `0700` and file mode `0600`, preserved it across the Bridge restart endpoint and a real worker restart, and limited restart cleanup to transient acknowledgement/result files;
- hardened final source-checkpoint verification to reject permission weakening and symbolic-link substitution at the private root, execution, checkpoint, snapshot, manifest, and file boundaries. A disposable checkpoint of the actual checkout independently verified 574 eligible files totaling 6,656,436 bytes and was removed after verification;
- repaired the Phase update route so its parameterized update, audit event, row lock, direct read-back, commit, and rollback share one checked transaction;
- re-ran every mandatory PHP suite, PHP lint for every changed or new PHP file and the installer, Bridge syntax checking, frontend lint/build, `git diff --check`, executable/compiled prohibited-transport and credential scans, and the disposable different-name source-unavailable lifecycle. The private copy rendered the portal and Phases, isolated its Bridge workspace, and passed database, Sharingan, and shared Planning/Coding workflow tests before exact cleanup;
- restarted the live Bridge only after confirming zero active runs and zero durable pending states. Health resolved the canonical current project, the visible Codex companion was ready, and only one loopback listener remained;
- reconciled the P3 summary in one ADODB transaction with an audit record and direct pre-commit plus post-commit read-back. The phase remains `In Progress`, the persisted summary SHA-256 is `3e5905367f4b569f19cd3061ef85909152587f1688ca997f6aa5699fb56fb1cc`, and no checklist row changed;
- confirmed that the first incomplete Milestone 5 security item is still the dedicated Coding operating-system identity. The live Bridge runs as the interactive desktop user so it can use that user's visible Codex Chat; no isolated identity with proven denial of other installations is provisioned. The installed user service is also symlinked to an unresolved project template, so it must not be daemon-reloaded until an administrator installs a rendered regular unit;
- left authenticated desktop/responsive acceptance pending because the only available browser session reached the Phase Manager sign-in screen. No credentials were entered. Clean-Git authorization, installer-template synchronization, a genuine fresh database/install, and deployed cross-install denial also remain pending.

### 2026-08-21 authenticated Sharingan acceptance and P3-T10 reconciliation

- exercised the authenticated User Portal (`/developer/`), Administrator Portal (`/developer/administrator/`), and Phases (`/developer/phases/`) surfaces sequentially at the restored desktop viewport. The User Portal capture exposed no account details; Administrator and Phases captures kept the shared sidebar and account details collapsed;
- selected only the approved non-sensitive controls: `Open Administrator` (`div > section > div > div:nth-of-type(2) > a`, `150.34375 x 36`), `Operational Dashboard` (`div > section:nth-of-type(1) > div > div:nth-of-type(1) > h2`, `717.609375 x 28`), and `Phase Manager` (`main > header > div:nth-of-type(1) > div:nth-of-type(2) > h1`, `212.734375 x 20`);
- submitted exactly one privacy-safe current screenshot per surface with zero attachments and the instruction to analyze layout and accessibility only without modifying files, source, configuration, database records, or product data;
- confirmed zero active database runs, zero durable pending Bridge requests, and an idle active Codex Chat before each submission. The three Bridge request IDs were `21ca217b-5f82-4e3d-8a43-97a2e967cdfa`, `1a35b722-617e-4456-b30d-519b267c643f`, and `1d22878e-33ba-4830-ba9e-019e5ea49179`;
- persisted Planning runs `8889fe8d-1888-4812-8832-76fb4ce74a9e`, `a0fe69fe-9c53-4048-8e78-a3fd4818061e`, and `77ec1375-d3b2-4f2c-968b-d762b1063c3b`. Every context, analysis, and persistence stage reached `SUCCEEDED`; all three validated `builderx.sharingan.analysis.v1` results contained `FND-001`, `CHG-001`, no blockers, and `requiresAdministratorApproval: true`;
- verified visible progress, terminal success feedback, persistent database rows, stage/chunk/event state, audit events, direct ADODB read-back, current-installation binding `5cb6e89477770658521dd2244c6b93e586b4eb52f60db8725efda17e0f1f50fd`, and completed mode-`0600` durable Bridge state for every request. No AI-proposed product change was applied and no Coding or Git action ran;
- repaired the only live defect found: the Sharingan panel now consumes the existing authenticated `load` endpoint and restores the latest validated `SUCCEEDED` analysis after a full-page reload without dispatching or retrying a run. Each of the three reports was then observed again after a true reload;
- verified each restored report at a temporary `390 x 844` viewport. Every report dialog remained within `16px` viewport insets at `358 x 812`, its close control remained visible, and document width stayed exactly `390px`; the viewport was reset to `2742 x 1291` afterward;
- built the repaired frontend as `frontend/dist/assets/index-WfqBBwrT.js` and preserved the existing authenticated User and Administrator Sharingan header toggles;
- completed only the two previously pending P3-T10 checklist rows in one checked ADODB transaction with exact-text validation, phase/task/checklist row locks, parameterized writes, two checklist audit events, one P3 summary audit event, direct pre-commit read-back, checked commit, and durable post-commit read-back. P3 remains `In Progress`, P3-T10 remains an open task with `5/5` checklist rows complete, and the persisted P3 summary SHA-256 is `33bc494064bc626aefa637d1de1cfa7bd38361dd7b0aa85a4f30f7bb7519cd31`;
- restored the browser viewport, left `/developer/phases/` open with the sidebar collapsed, disabled Sharingan, and closed all Sharingan panels and dialogs.

### Next actionable Milestone 5 security and release gate

The first incomplete Milestone 5 security gate remains a dedicated Coding operating-system identity. Its base user, group, login password, and installation-specific filesystem ACL boundary now exist and have direct read-back, but the gate remains externally blocked until an administrator starts the required separate graphical session and authenticated visible Codex Chat, installs the rendered regular user service in that session, and completes one bounded Coding lifecycle under that identity. The current Bridge worker still runs as the interactive desktop user and therefore does not yet satisfy the full boundary.

The 2026-08-21 read-only provisioning preflight initially confirmed that the proposed `builderx-coding-developer` user and group were absent, the current interactive account owned the only graphical login and the live loopback Bridge process, and there were zero active AI runs. After explicit administrator authorization, the dedicated group and user were created with a private home, `/bin/bash`, and no supplementary groups. The account was initially password-locked; the administrator subsequently set its separate login password locally and supplied only the non-secret `passwd --status` result `P` as read-back. No password value was recorded. Direct read-back confirmed the identity and primary-group binding, absence from `sudo`, `builderx`, and the interactive user's groups, and an owned mode-`0750` home. No ACL, graphical session, service, Git, installer, or database change accompanied this identity-only provisioning, and the live Bridge remains under the current interactive user.

`/var/www/html` resolves to the mounted canonical web root on an ACL-capable `ext4` filesystem. A direct inventory using the legacy `system/installation.php` and current `app/foundation.php` installation signatures identified 33 canonical BuilderX roots: the current Developer root plus 32 other roots that require explicit named-user denial. That inventory must be regenerated and reviewed immediately before the ACL step so a newly added or renamed installation cannot be omitted.

A guarded administrator helper was prepared and executed from `tools/permission-generator/provision-coding-acl.sh` with SHA-256 `b4abc3f1f5458aeb96b1685587bb6ced074ff372b212dbc7f62a44882287acae`. It refused a non-root caller during static verification and was designed to refuse a changed 33-root inventory, an unexpected group or session, a non-`P` password status, active AI runs, a path outside the canonical web root, or a pre-existing partial ACL. Before changing access it wrote root-only recursive and boundary ACL snapshots and a rollback script under `/var/backups/builderx-coding-acl/20260821T080447Z.4dmwCG`. It then granted parent traversal plus recursive/default `rwX` only to Developer, set an explicit named-user `---` entry on every other inventoried BuilderX root, completed its temporary runtime write/read cleanup test, and directly verified read, directory-entry, and file-read denial on all 32 other roots.

Independent post-run read-back confirmed the named `--x` entries on `/var/www` and the canonical web root, `rwx` plus default `rwx` on Developer, `rw-` on the representative frontend source file, recursive access entries on all 27,974 current project filesystem entries, default entries on all 2,703 current project directories, and explicit `---` entries on all 32 other roots. There are zero active AI runs, the dedicated graphical session is still absent, and the current interactive user's symlinked service is unchanged. P3-T07 remains pending because its exact text requires the system to run under the dedicated identity; ACL provisioning and `runuser` permission verification alone do not prove a visible Bridge/Coding lifecycle. Only the P3 summary was reconciled in a checked ADODB transaction with phase and checklist row locks, parameterized SQL, audit `e822c6a2-ad79-4cb2-af79-8f8c57d52ade`, transaction-local read-back, checked commit, and durable post-commit read-back. P3 remains `In Progress`, P3-T07 remains `Pending`, and the persisted summary SHA-256 is `64b5e58af39ad79d9ee04c2aabc9478f28dd7405ee2b4cb9decc2629506cec14`.

The executed non-technical administrator entry point is retained below for audit reproducibility. Do not run it again against the completed ACL state; the helper will refuse the pre-existing entries. The sudo password was entered only into the local terminal prompt and was never an argument, file, environment value, or project record:

```bash
ACL_SCRIPT=/var/www/html/developer/tools/permission-generator/provision-coding-acl.sh
EXPECTED_SHA=b4abc3f1f5458aeb96b1685587bb6ced074ff372b212dbc7f62a44882287acae
test "$(sha256sum "$ACL_SCRIPT" | awk '{print $1}')" = "$EXPECTED_SHA" \
  && sudo test ! -e /root/builderx-provision-coding-acl \
  && sudo install -o root -g root -m 0700 "$ACL_SCRIPT" /root/builderx-provision-coding-acl \
  && test "$(sudo sha256sum /root/builderx-provision-coding-acl | awk '{print $1}')" = "$EXPECTED_SHA" \
  && sudo /root/builderx-provision-coding-acl \
  && sudo unlink /root/builderx-provision-coding-acl
```

A separate authenticated graphical session is required under the present architecture. The BuilderX Bridge deliberately delivers to an active, visible Codex Chat owned by the operating-system user's VS Code session; it does not use an API key, Codex CLI, shared hidden worker, or another user's desktop bus. A dedicated Coding user must therefore have its own graphical login, its own VS Code profile and keyring, the BuilderX companion extension, and an authenticated visible Codex Chat. Reusing the current interactive user's graphical session, extension host, home directory, or authenticated Chat would collapse the intended identity boundary. If the host cannot provide a second graphical session that still exposes the approved visible Chat, this gate stays blocked and the architecture must be reconsidered explicitly rather than bypassed.

The base user, group, password, and ACL steps are complete and must not be repeated. For reference, the helper applied and verified the following ACL primitives transactionally with its broader inventory, snapshot, and rollback guards; these raw commands were not executed independently:

```bash
CODING_USER=builderx-coding-developer
PROJECT_ROOT=/var/www/html/developer

# Grant only parent traversal and current-project access.
sudo setfacl -m "u:${CODING_USER}:--x" /var/www /var/www/html
sudo setfacl -R -m "u:${CODING_USER}:rwX" "$PROJECT_ROOT"
sudo find "$PROJECT_ROOT" -type d -exec setfacl -m "d:u:${CODING_USER}:rwx" {} +

# Repeat this explicit deny for every other canonical BuilderX installation.
OTHER_ROOT=/var/www/html/OTHER_BUILDERX_INSTALLATION
sudo setfacl -m "u:${CODING_USER}:---" "$OTHER_ROOT"
```

The administrator must enumerate every other installation explicitly after resolving it with `realpath`; a wildcard or an assumed partial list is not acceptance evidence. The dedicated user must not be added to `sudo`, the broad `builderx` group, another installation's group, or the current interactive user's group.

Next, the administrator must start a separate graphical login as `builderx-coding-developer`, open `/var/www/html/developer` in that user's VS Code, install/verify the official Codex and BuilderX companion extensions, authenticate the visible Codex Chat in that user's own profile, and render a regular user service inside that graphical session:

```bash
PROJECT_ROOT=/var/www/html/developer
SERVICE_TARGET="$HOME/.config/systemd/user/builderx-bridge.service"
mkdir -p "$HOME/.config/systemd/user"
test ! -L "$SERVICE_TARGET"
SERVICE_CANDIDATE="$(mktemp "$HOME/.config/systemd/user/builderx-bridge.service.XXXXXX")"
sed "s|__BUILDERX_PROJECT_ROOT__|$PROJECT_ROOT|g" \
  "$PROJECT_ROOT/tools/builderx-bridge/builderx-bridge.service" > "$SERVICE_CANDIDATE"
! grep -Fq '__BUILDERX_PROJECT_ROOT__' "$SERVICE_CANDIDATE"
install -m 0600 "$SERVICE_CANDIDATE" "$SERVICE_TARGET"
unlink "$SERVICE_CANDIDATE"
test -f "$SERVICE_TARGET" && test ! -L "$SERVICE_TARGET"
systemctl --user daemon-reload
systemctl --user enable --now builderx-bridge.service
code --new-window "$PROJECT_ROOT"
```

After the visible Codex Chat is authenticated and idle in that separate session, the administrator must perform direct allow/deny and service read-back:

```bash
CODING_USER=builderx-coding-developer
PROJECT_ROOT=/var/www/html/developer
OTHER_ROOT=/var/www/html/OTHER_BUILDERX_INSTALLATION

getent passwd "$CODING_USER"
id "$CODING_USER"
getfacl -p /var/www /var/www/html "$PROJECT_ROOT" "$OTHER_ROOT"
sudo -u "$CODING_USER" -- test -r "$PROJECT_ROOT/frontend/src/App.tsx"
sudo -u "$CODING_USER" -- test -w "$PROJECT_ROOT/.builderx/runtime"
sudo -u "$CODING_USER" -- sh -c 'cd "$1" && test -r frontend/src/App.tsx' sh "$PROJECT_ROOT"
! sudo -u "$CODING_USER" -- test -r "$OTHER_ROOT"
! sudo -u "$CODING_USER" -- sh -c 'cd "$1"' sh "$OTHER_ROOT"
! sudo -u "$CODING_USER" -- head -c 1 "$OTHER_ROOT/index.php" >/dev/null
test -f "$HOME/.config/systemd/user/builderx-bridge.service"
test ! -L "$HOME/.config/systemd/user/builderx-bridge.service"
! grep -Fq '__BUILDERX_PROJECT_ROOT__' "$HOME/.config/systemd/user/builderx-bridge.service"
curl --fail --silent --show-error --get \
  --data-urlencode "workspace_root=$PROJECT_ROOT" \
  http://127.0.0.1:43127/health | jq -e \
  --arg workspace "$PROJECT_ROOT" \
  '.ok == true and .workspace == $workspace and .active_thread_ready == true and .active_thread_busy == false and .ready_to_send == true'
```

The denial commands must be repeated for every other canonical installation and must fail because of the dedicated user's named ACL, not merely because a file happens to be absent. Acceptance also requires one bounded Coding request in the separate visible Chat, source/checkpoint persistence and read-back inside the current project, and repeated denial after that request. No cross-install database denial, clean-Git authorization, installer synchronization, or fresh-install claim is implied by this host-identity proof.

The currently installed service remains a symbolic link from `$HOME/.config/systemd/user/builderx-bridge.service` to the project template, whose `WorkingDirectory` and `ExecStart` still contain `__BUILDERX_PROJECT_ROOT__`. The running worker is healthy only because systemd previously loaded a usable unit state. Do not run `systemctl --user daemon-reload` against this symlink. An administrator must first install the rendered regular mode-`0600` unit above in the correct dedicated graphical session.

After the identity gate passes, the release gate still requires explicit clean-Git authorization, one-way installer-template synchronization, a genuine fresh database and differently named installation, deployed-copy web/worker/Sharingan/two-engine verification with Developer unavailable, and direct denial of every other installation and database. None of those release actions ran in this continuation.

### 2026-08-21 rbmsv5 inventory and deployment acceptance continuation

- installed an isolated `/var/www/html/rbmsv5` earlier in this acceptance run without changing `/var/www/html/rbmsv4`, then completed the inventory Planning Engine lifecycle against its own `rbmsv5_inventory` database. Requirements run `e2241363-4a14-4cca-832d-08e4930cd881`, architecture run `83b7d36e-4f01-4832-9a66-cdfc9433a1aa`, UI/UX run `18033781-e4da-482a-a61b-39761cb7e096`, and roadmap record `03098f0f-30d3-4c6e-8cb2-92157b6d5b52` produced the saved `builderx.execution-roadmap.v3` hierarchy with 8 phases, 40 tasks, 80 sub-tasks, 254 todos, 48 proposed resources, and 10 proposed tables;
- completed one User Portal Coding lifecycle with execution `a2375a74-b80d-4d43-96b2-ba154447ed74`, run `e83d62fb-b431-46b2-bc25-1b5cb3926d64`, source checkpoint `912571a8-add2-4d39-9870-0c260277f34e`, and inspection/plan/implementation/verification/evidence requests `0544c8a1-1248-45d0-b64a-64c6ad070780`, `f5ab53a8-ec35-40fd-915b-ee4dde8c57eb`, `e7b9968f-d5ee-411b-b116-662ee293c33e`, `4a19fd76-085c-45ac-a876-cb8dc73f2428`, and `5e35d6ee-f1d6-472f-927f-61ded02a53d8`. The authenticated catalog passed two full reloads, anonymous denial, current-user projection limited to `user_key` and `user_name`, active-product filtering, and exclusion of unit cost;
- completed one Administrator Coding lifecycle with execution `139ef47a-ef53-428f-bea0-c67f660389ea`, run `50b5ec90-70ae-4906-b4f5-bc123eee674a`, source checkpoint `33dbbe52-baca-4dde-804e-d66c0bb6ba4f`, and request IDs `cc5580a9-65f3-4026-a22a-8a1d41fd5af3`, `ba83efaf-b278-4267-9e93-51682ba594de`, `7eb3663f-29dc-4c7f-bbf3-58525f71b164`, `5242cf1c-c2bf-4d8e-84de-925adb404968`, and `4e5377db-2ef6-4940-81a3-ac3ab25e3990`. All persisted stages, Coding persistence, execution log, source checkpoint, and result hash `d9fdb3db40dffab642c7f81deda7d075b0b406d4a0e6859e3469e0eedb0a77ca` read back as successful; Git remained skipped;
- exercised the live Administrator product route with authenticated HTTP and direct ADODB read-back: create, exact idempotency replay, stale-version rejection, complete edit, duplicate-SKU rejection, invalid-CSRF rejection, non-destructive archive, two archived-page reloads with identical product JSON, User Portal visibility before archive, and User Portal exclusion after archive. Inventory events, sync outbox records, BuilderX audits, idempotency rows, event keys, and correlation IDs remained one-to-one;
- repaired the repeated-test invariant that a newly created product projected a computed zero quantity without a durable balance row. The create transaction now initializes `inventory_stock_balances` without resetting an existing quantity, verifies the balance under the pre-commit row lock, and repeats the balance read-back after commit. The schema apply path backfilled the earlier archived probe and recorded migration audit `95a90aef-6af8-4d62-9a45-09fadca4061d`. A new live product `16b25e39-2867-495c-ae1c-73d902943116` created balance `8` at `0.000`, then archived non-destructively at version 2 while retaining that balance. Create/archive event keys `7b0d3fd9-ea29-4d41-8424-311c5de7d435` and `65a4385c-5948-4010-899e-1aec2b76cf46`, correlations `75f78827-33cc-4c30-afc6-3bc7d079c6a9` and `e2f1a415-4dea-4473-b651-a768d0143346`, and BuilderX audits `4823c5e1-a3e6-444e-bed5-62117ccb7ede` and `01b8d080-5ed2-4b77-a84c-413e87f8e5ed` read back directly;
- repaired the shared current-roadmap export gap. `builderx.execution-roadmap.v2` and `.v3` now render and export Web, Mobile, and Shared tasks, preserve the task/sub-task/todo hierarchy in metadata, use content-independent stable identities, preserve completion state on update, reject duplicate identities, audit the write, and compare direct pre-commit with durable post-commit read-back. An authenticated rbmsv5 export created 5 Phase 01 tasks, and an exact replay created 0, updated the same 5 task keys, and persisted audits `ee4a2535-e120-42f4-a12e-60b31edd59b7` and `ac81dc1b-1376-4e35-a747-2173f795475a`. The shared source and compiled build were synchronized to the working installer template; the later dependency/build hardening pass below records the current authoritative manifest targets;
- proved deployed-copy isolation beyond a string scan. The rbmsv5 database account sees only `information_schema` and `rbmsv5_inventory`, has a database-scoped grant, and received real denials for `developer`, `rbmsv4`, and the unrelated `rbmsv5` schema. Runtime/source/compiled scans against both `/var/www/html/developer` and `/var/www/html/rbmsv4` passed. Portal, Administrator, and Phases returned HTTP 200 and a separately started Bridge resolved workspace `/var/www/html/rbmsv5` while Developer and rbmsv4 were simultaneously bind-hidden inside a temporary isolated mount namespace;
- completed only checklist `ec3d67c0-0ded-4a79-bd32-6dfac8a09d1b` under P3-T08 in one checked ADODB transaction after exact-text validation and phase/task/checklist row locks. Parameterized writes, checklist audit `1dfe44ae-9188-4ab8-a1aa-351cb3522b85`, P3 summary audit `fa824941-f8a6-45d7-be49-89d572b6376c`, direct pre-commit read-back, checked commit, and durable post-commit read-back all passed. P3 remains `In Progress`; P3-T08 is now `5/5`, and the persisted P3 summary SHA-256 is `75b1e2d19be6bfc883328f6ea322633eff401b830805cc1450bd31378e1b2027`;
- left every unsupported row pending. The dedicated account and ACL exist, but no separate authenticated graphical session has run the visible Codex Chat and Bridge under that identity. The official installer correctly refuses the 52-path dirty Developer worktree: its source manifest is still bound to revision `277dd4848b78b5598eab3d2a49778b95c73f90b4`, so no manifest was forged and no post-change fresh installer deployment was claimed. The synchronized template itself passed fresh-state isolation with 319 runtime files scanned, zero generated runtime files, and zero symlinks. A real clean-Git-authorized template refresh, genuine fresh database/install, complete fresh Sharingan/two-engine lifecycle, and release rollback remain open;
- left the unresolved symlinked user service unchanged and did not run `systemctl --user daemon-reload`. The authenticated rbmsv5 product dialogs were verified through HTTP/database lifecycle and source/build tests; in-app authenticated visual dialog/responsive click-through remains a bounded acceptance gap because entering the browser password requires a new action-time confirmation.

### 2026-08-21 frontend dependency and production-bundle hardening

- reviewed every reported frontend advisory before changing the lockfile. All nine findings were transitive dependencies with ordinary non-breaking fixes available: 3 moderate and 6 high findings across `@hono/node-server`, `brace-expansion`, `fast-uri`, `hono`, `ip-address`, `js-yaml`, `nanoid`, `postcss`, and `undici`. `npm audit fix` changed only those transitive resolutions; direct dependency ranges and `package.json` did not change;
- reduced the previous approximately 1.01 MB monolithic production JavaScript entry by separating React, Base UI, icons, and remaining vendor code with the installed Vite 8/Rolldown `manualChunks` interface. The explicit JavaScript warning budget is 650 kB. Developer and the clean working template now emit an identical 596,317-byte entry with no oversized-chunk warning; inventory-specific rbmsv5 emits a 622,032-byte entry, also within budget;
- synchronized and directly hash-compared `frontend/package-lock.json` and `frontend/vite.config.ts` across Developer, the working installer template, and rbmsv5. Developer/template were independently built from a clean `npm ci` copy and their complete `dist` trees compare byte-for-byte. The authoritative Developer/template manifest target is `frontend/dist/assets/index-BlPp58VU.js`; rbmsv5 targets `frontend/dist/assets/index-CIu02gfA.js`;
- re-ran frontend lint and production builds for Developer, the working template, and rbmsv5. All builds passed without a chunk-size warning; lint retained the same 13 known warnings and introduced no errors. Independent `npm audit` read-back reports 0 low, moderate, high, or critical findings for all three lockfile-identical targets;
- re-ran 10 focused Developer contract suites and the rbmsv5 Administrator product, User catalog, roadmap-export, and user-projection suites. The protected rbmsv5 local configuration correctly remained unreadable to the desktop account and its dependent projection suite passed as `www-data`; broad lint passed for 265 Developer PHP files including entrypoints and 270 rbmsv5 PHP files including the protected configuration, and both Bridge entrypoints passed `node --check`;
- rechecked all six Developer/rbmsv5 Portal, Administrator, and Phases URLs at HTTP 200, manifest-target existence, `git diff --check`, browser transport/provider/Codex-CLI scans, and installed-copy source-path isolation. The working template scanned 324 runtime files with zero generated files and zero symlinks; the exercised rbmsv5 copy scanned 328 runtime files and its populated database without a Developer or rbmsv4 reference. Two stale isolated-port rbmsv5 test workers had no active Chat or pending request and were terminated; the active port-43127 Bridge remained ready, idle, and bound to `/var/www/html/rbmsv5`;
- directly read back all five Planning/Coding acceptance runs as `SUCCEEDED`, both Coding execution logs as `COMPLETED`, the complete eight-stage Coding chains, archived inventory products and durable zero balances, one-to-one inventory audit/outbox correlations, zero active rbmsv5 runs, and the three authenticated Sharingan runs with three successful stages and eight events each. Final P3 read-back remains `In Progress` at 45/50 completed checklist rows with summary SHA-256 `75b1e2d19be6bfc883328f6ea322633eff401b830805cc1450bd31378e1b2027`; none of the five pending exact clauses became fully supported, so no additional checklist transaction was performed;
- restored the in-app browser to one `/developer/phases/` tab at its normal `1982 x 1291` viewport, signed out, with zero visible dialogs and no active Sharingan control or panel;
- this hardening does not close the release gate. The installer source manifest is still bound to clean revision `277dd4848b78b5598eab3d2a49778b95c73f90b4`; the dirty Developer worktree still requires explicit clean-Git authorization before an official template refresh and genuine fresh-install acceptance.

### 2026-08-21 Git-versioned installer and Ubuntu release continuation

- made `deploy/installer` the Git source for the external `/var/www/html/_installer` application and added checked application deployment, release building, checksum-bound template publication, and desktop Bridge installation helpers. The deployed `.htaccess`, `index.php`, `download-codex-bridge.php`, and secret-free `config.example.php` directly hash-match the versioned sources. `config.local.php`, the clean template, releases, projects, and databases are never overwritten by the application deployer;
- restricted the installer to loopback in both PHP and Apache policy, disabled browser-based template refresh, denied direct HTTP access to protected configuration, release/template trees, and template entrypoints, and corrected the obsolete Visual Codex download/prompt to the current versioned `builderx-companion-1.5.1.vsix`, `tools/builderx-bridge/server.mjs`, and project-local `install-user-service.sh`. Live HTTP read-back returned 200 for the installer and companion download and 403 for `config.local.php` plus the clean-template entrypoint;
- hardened project creation with a required existing Ubuntu desktop user, non-repopulating password fields, client and server password confirmation, an accessible review dialog before submission, visible progress, POST/Redirect/GET success handling, explicit rollback messaging, current-project permission and Bridge commands, and a refusal to operate when the canonical web root is world-writable. MySQL provisioning now refuses a pre-existing generated account, performs schema/account/project-account read-back, and retains compensating cleanup for non-transactional MySQL DDL;
- wrapped initial Administrator creation and its role/group/audit verification in a checked ADODB transaction with direct pre-commit and durable post-commit read-back. A successful new installation now records a separate privacy-safe `builder_installation` audit event in a checked ADODB transaction only after isolation and web lifecycle verification. No installer form was submitted in this continuation, so no project, MySQL account, database, Administrator, or installation audit was created;
- consolidated `install.md` as the authoritative new-Ubuntu, new-project, Git-release, migration, permission, visible-Codex, and dedicated-Coding guide. `CODEX_INSTALL.md` is explicitly supplementary, and `docs/project/git-installer-source.md` now points to the consolidated contract. The guide documents the protected local MySQL configuration, temporary named web-root ACL with saved restore evidence, clean commit requirement, release/publish commands, project permission finalizer, user-service helper, fresh-install tests, manual graphical-session boundary, and separate Git-push authorization;
- the final disposable clean-Git acceptance built revision `b1cd88724b3993d00af7304ae7dccf8ac4dab5be` from the complete current file set without modifying Developer Git. Frontend lint retained 13 known warnings and no errors; the build targeted `assets/index-BlPp58VU.js`; PHP, Bridge, and shell syntax passed; installed-project isolation scanned 324 runtime files with zero generated runtime files and zero symlinks. Release archive `builderx-b1cd887.tar.gz` contains 630 files, SHA-256 `dae79a5b050d85c1ca56ec3234c707dc6dc2c66279224b3154e3ac6292efcd9e`, and excludes Developer-only installer sources, release scripts, and static installer tests while retaining the source manifest, compiled frontend, and project Bridge helper. This disposable revision and archive are acceptance artifacts, not an official Git commit or published release;
- live browser acceptance passed at the normal 1280-pixel viewport and a temporary 390 by 844 viewport. The mobile page had no horizontal overflow, the form preceded the explanatory rail, the action filled the available width, password fields remained empty after reload, blocked preflight kept the action disabled, and a sanitized static interaction displayed the exact project/folder/database/Administrator/desktop-user confirmation before any POST. The dialog was cancelled, the viewport was reset, and the temporary tab/server were closed;
- final verification passed `git diff --check`, 271 PHP files, all changed shell helpers, both Bridge JavaScript entrypoints, installer static acceptance, npm audit at zero vulnerabilities, frontend lint/build, current manifest-target existence and SHA-256 `38ad40ced9eced6a4dd9284e1ba7563e0f3ec8818ea41ffc0c68bf8de4c7cb0a`, retired installer/Bridge scans, Developer Portal/Administrator/Phases HTTP 200, and direct deployed/versioned installer parity. The official installer remains safely blocked because `/var/www/html` is currently world-writable and Developer has 63 dirty paths;
- independently re-read P3 after the installer work. It remains `In Progress` at 45/50, with five exact pending clauses and summary SHA-256 `75b1e2d19be6bfc883328f6ea322633eff401b830805cc1450bd31378e1b2027`. No pending clause is fully supported by a disposable release archive or UI-only acceptance, so no checklist/database transaction was performed. There are zero active Developer AI runs;
- did not publish or mutate the official clean template. It remains bound to `.builderx-acceptance-source.portable.kV5IRMCN@277dd4848b78b5598eab3d2a49778b95c73f90b4`, targets `assets/index-BlPp58VU.js`, and does not yet contain the current Bridge 1.5.2 companion and recovery-aware user-service helper. The former unresolved service symlink was repaired separately on 2026-08-22 for the live `rbmsv6` workspace; the protected official template still requires the reviewed clean-Git release and privileged atomic publisher before it can be used for new installations;
- the next release action is intentionally manual: protect `/var/www/html` and grant only a saved temporary `www-data` ACL window, review and commit the complete Developer worktree, run `scripts/build-installer-release.sh`, publish the exact manifest-bound archive through `scripts/publish-installer-template.sh`, confirm preflight is ready, and perform a genuine differently named installation with a new database. After the displayed permission and Bridge helpers pass, verify all routes, database/audits, source-unavailable web and worker lifecycle, cross-install filesystem and database denial, release rollback, and the separate authenticated graphical Coding identity before changing any remaining P3 row.

## Authoritative source and installer rule

`/var/www/html/developer` is the original BuilderX source and must receive every overhaul change first.

The installer must not become an independent development source. The required release direction is:

```text
/var/www/html/developer
        -> verification and acceptance
        -> clean installer-template synchronization
        -> fresh-project installation test
```

Installer synchronization is allowed only after the Developer implementation passes its acceptance checks.

### Self-contained deployed-copy rule

`/var/www/html/developer` is the authoritative development and release source only. It must never be a runtime dependency of an installed BuilderX project.

After deployment, every BuilderX copy must operate entirely from its own project directory. This is a non-negotiable portability and isolation contract:

- filesystem paths must be resolved from the installed copy's canonical project root, using the executing file location rather than a fixed server path;
- browser URLs and base paths must be derived from the current request and installed mount path;
- PHP includes, configuration loading, frontend requests, AI Bridge workspace context, runtime directories, logs, MySQL transport rows, document paths, uploads, and Git scope must all resolve inside the current project;
- browser-storage keys that contain project-specific state must be namespaced by the current installation identity so one localhost project cannot reuse another project's workspace value;
- an installed copy must not contain a symlink, generated configuration value, cached path, database value, or compiled frontend string that points to `/var/www/html/developer` or any other source checkout;
- environment overrides may configure services and credentials, but they must not silently redirect the current project's filesystem root to the Developer source;
- if an installation-specific file or runtime directory does not exist, that installation must create its own empty resource with the installer-defined ownership and permissions; it must never copy or fall back to an older project's resource;
- deleting, renaming, unmounting, or making `/var/www/html/developer` unavailable after deployment must not break the installed project's web UI, Phase Builder, Phase Manager, AI Bridge workspace resolution, database transport, document storage, or Git scope.

The installer acceptance test must deploy to a different directory name, make the Developer source unavailable to the test process, and verify the installed copy through its actual web and worker lifecycle. A source-code search alone is not sufficient. The test must also reject absolute Developer paths in executable source, compiled assets, generated configuration, database configuration/state, and symlink targets.

The installer must exclude all project-specific or generated state, including:

- MySQL data and database exports;
- AI run, stage, chunk, and event records;
- generated JSON context and result files;
- logs, caches, locks, queues, and temporary files;
- uploads and attachments that belong to an existing project;
- local credentials and `config.local.php` values;
- hardcoded workspace or installation paths.

Every new installation must create its own empty database records and runtime directories and must never read another installation's database or generated files.

## Current architecture decisions

### MCP direction canceled

MCP is not the required runtime architecture for this overhaul. It was canceled as the primary solution because BuilderX installations may run on computers with significantly different hardware and software capabilities.

No MCP server should be added unless a later explicit decision restores it.

### Required AI Bridge and Codex AI Chat runtime

BuilderX is intentionally dependent on the existing BuilderX AI Bridge and an active Codex AI Chat. The bridge is the required AI transport for the whole project and must not be removed, replaced, or treated as temporary during this overhaul.

Both Phase Builder and Phase Manager must use one shared bridge adapter so transport health, active-chat readiness, request identifiers, progress, results, timeouts, and failures are handled consistently. The persistent BuilderX run lifecycle remains authoritative even though the model request is delivered through the bridge.

The target design must not depend on:

- Codex CLI;
- MCP;
- a direct OpenAI API integration maintained by BuilderX;
- a hidden provider or cloud fallback;
- multiple autonomous AI agents or specialist-agent fan-out.

Codex AI Chat is the current required AI runtime. BuilderX must record the bridge request ID and any model or provider identity exposed by the bridge, but it must not silently switch to another provider when the bridge or active chat is unavailable.

### Sharingan surface feature

Sharingan is an approved surface feature of the two-engine architecture and may be updated wherever necessary to work directly within the current User Portal (`/developer/`), Administrator Portal (`/developer/administrator/`), and Phases (`/developer/phases/`) surface. It must preserve the visible route, selected element, installed-project identity, and current authorization boundary while capturing, annotating, generating context, submitting through the Bridge, receiving a result, and displaying completion or failure feedback.

Sharingan is not a third AI engine, provider, autonomous agent, or agent fan-out mechanism. It must use the same server-owned BuilderX AI Bridge adapter and active Codex AI Chat lifecycle approved for the Planning and Coding engines, with no browser-to-Bridge bypass and no alternate transport or provider fallback.

Non-privileged capture, selection, and annotation may be exposed to an authenticated User Portal user where product rules allow it, but that surface must not grant Administrator, Planning Engine, Coding Engine, source-write, Git, or product-mutation authority. Administrator-only and coding operations remain protected by the established Administrator session, backend authorization, saved scope, CSRF validation, transactional persistence, audit logging, and direct read-back.

### Exactly two AI engines

#### 1. Phase Builder Planning Engine

The planning engine owns:

- Narrative & Cleanup;
- Requirements Analysis;
- System Architecture;
- UI/UX Design;
- Execution Roadmap.

It is planning-only. It must not edit source files, execute arbitrary shell commands, or directly mutate product tables. Application code validates every result before a transaction, audit event, commit, and database read-back.

#### 2. Phase Manager Coding Engine

The coding engine owns:

- implementation of an approved todo;
- bounded project-file changes;
- authorized database migrations and data changes;
- focused lint, tests, builds, and read-back verification;
- bounded Git inspection, staging, commit preparation, and approved repository updates;
- change evidence and verified rollback.

It must not delegate to additional AI agents. It must not access another installation or modify the BuilderX control plane unless that exact scope is explicitly approved.

### Specialist names are stage instructions, not agents

Labels such as Requirements Specialist, Database Specialist, UI/UX Specialist, Security Specialist, or Git Update Specialist may be used as prompt perspectives, stage profiles, or validation categories. They must not dispatch separate AI agents.

The Git Update Specialist belongs only to the Phase Manager Coding Engine. It is a bounded stage executed by the same coding engine after implementation and verification. It may inspect repository status and diffs, identify unrelated user changes, stage only approved files, prepare an evidence-backed commit, and perform the exact Git update authorized by the user. Pushes, tags, branch deletion, force operations, history rewrites, and remote changes require explicit approval and must never happen implicitly.

A chunk is also not another agent. The same configured engine processes bounded chunks sequentially and persists each checkpoint.

## Multi-layer engine architecture

The application controls the workflow. The AI processes one bounded assignment at a time.

```text
User request
  -> persistent run
  -> deterministic stage plan
  -> bounded context construction
  -> one semantic chunk
  -> strict result validation
  -> persistent checkpoint
  -> next chunk or deterministic merge
  -> final integration review
  -> transaction, audit, and read-back
```

### Layer 1: Deterministic orchestrator

Normal application code determines:

- the next stage;
- required chunks and their order;
- valid upstream artifacts;
- retry eligibility;
- merge readiness;
- cancellation and expiration behavior.

The AI must not control the workflow order.

### Layer 2: Context builder

Every request should contain only:

- global project rules;
- current stage instructions;
- immutable IDs and canonical terminology;
- the relevant upstream summary;
- the current semantic chunk;
- the required response schema.

The engine must not resend the complete project on every request.

### Layer 3: Stage and chunk runner

The runner calls the configured model once for the current chunk and enforces:

- input and output token budgets;
- timeout and cancellation;
- bounded retry count;
- structured JSON output;
- provider and model identity recording;
- no unconfigured fallback.

### Layer 4: Validator

Application code validates:

- required JSON keys and types;
- immutable IDs;
- source hashes;
- allowed enum values;
- size limits;
- required relationships;
- forbidden changes;
- stale upstream data.

Invalid output does not advance to the next stage and is not reported as completed.

### Layer 5: Merger and integration review

Application code first merges validated chunks deterministically. One final bounded AI review may identify contradictions, duplicate requirements, naming differences, missing dependencies, or traceability gaps.

The integration review returns corrections or findings. It must not replace the complete artifact with an unrelated response.

### Layer 6: Persistence and read-back

Every run, stage, chunk, and event is saved before the workflow continues. Browser reload, navigation, or a closed modal must not lose progress.

## Semantic chunk strategy

Chunks are divided by meaning, not arbitrary character count.

### Narrative & Cleanup

Process the existing narrative sections independently, preserve all meaning and technical identifiers, merge them deterministically, and perform a final preservation review.

### Requirements Analysis

Required sequential semantic chunks:

- actors and roles;
- functional requirements;
- User Portal;
- Administrator Portal;
- Android application;
- database and synchronization;
- security and permissions;
- validation and recovery;
- deployment and operations.

### System Architecture

Suggested chunks:

- authentication and authorization;
- web surfaces;
- Android application;
- API layer;
- database;
- synchronization;
- background processing;
- security and operations.

### UI/UX Design

Chunk by product surface, module, or bounded screen group. Each chunk must preserve routes, states, accessibility, responsive behavior, and upstream traceability.

### Execution Roadmap

Chunk by module. Prefer one or two bounded model operations per module that produce its phases, tasks, sub-tasks, todos, and implementation resources. Avoid a separate model call for every small hierarchy level when the bounded output can be validated together.

### Coding engine

Use this checkpoint cycle:

```text
Inspect approved todo
  -> create bounded implementation plan
  -> apply one change chunk
  -> run focused verification
  -> save evidence
  -> continue with the next chunk
  -> run final integration verification
```

Typical coding chunks are database migration, backend behavior, API action, React UI, Android implementation, tests, integration verification, and an optional Git Update Specialist stage.

The Git stage runs only after the implementation and deterministic verification stages succeed. It must record the repository root, branch, starting and ending commit, status and diff summary, files staged, commit ID when created, remote operation when explicitly approved, and any unrelated changes left untouched. Git failure must not erase successful implementation evidence or cause an unverified implementation to be reported as fully released.

## Persistent run model

The persistent database is the primary workflow source of truth.

Required tables:

```text
phase_builder_ai_run
phase_builder_ai_run_stage
phase_builder_ai_run_chunk
phase_builder_ai_run_event
```

### Run fields

At minimum, a run should store:

```text
run_key
engine_type
workflow_key
stage_key
draft_key
phase_key
task_id
subtask_id
todo_id
source_hash
request_version
idempotency_key
status
attempt
max_attempts
provider_key
model_key
provider_request_id
worker_id
locked_until
request_json
result_json
error_code
error_detail
created_by_user_key
created_at
started_at
heartbeat_at
completed_at
```

Recommended statuses:

```text
QUEUED
RUNNING
VALIDATING
SUCCEEDED
FAILED
CANCELLED
EXPIRED
```

### Chunk fields

At minimum, a chunk should store:

```text
chunk_key
run_key
stage_key
chunk_type
chunk_order
source_hash
status
attempt_count
request_json
result_json
error_code
started_at
completed_at
```

Only the failed or expired chunk should retry. Successful chunks remain available as verified checkpoints.

### Required error codes

Use precise errors rather than a generic AI failure:

```text
RUN_TIMEOUT
STAGE_TIMEOUT
CHUNK_TIMEOUT
PROVIDER_UNAVAILABLE
MODEL_UNAVAILABLE
BRIDGE_UNAVAILABLE
CODEX_CHAT_NOT_READY
INVALID_RESULT_SCHEMA
SOURCE_CHANGED
CONTEXT_LIMIT_EXCEEDED
LOCK_CONFLICT
PERMISSION_DENIED
PERSISTENCE_FAILED
READ_BACK_FAILED
GIT_NOT_AVAILABLE
GIT_DIRTY_CONFLICT
GIT_UPDATE_FAILED
```

## MySQL exchange and document storage

The earlier directional filesystem exchange is retired. It caused repeated ownership, group, ACL, stale-path, service-binding, and fresh-install failures. Phase Builder and Phase Manager now use MySQL directly for both directions:

```text
Browser -> PHP -> phase_builder_ai_context / phase_builder_ai_job
VS Code BuilderX companion -> installed-project MySQL helper
Visible Codex Chat -> companion -> phase_builder_ai_job result_json
PHP -> schema validation -> persistent run/stage/chunk tables -> browser read-back
```

The transport contract is:

- `context_json`, `prompt_text`, progress events, `result_json`, errors, claims, timestamps, source hashes, run/stage/chunk identity, owner, and current-project identity are database values;
- every write uses parameterized ADODB SQL inside a checked transaction with audit and direct read-back;
- the companion receives only a job key over loopback; it claims and completes the matching current-project row through `tools/builderx-ai-job.php`;
- no phase workflow materializes a request, context, acknowledgement, progress, or result JSON file;
- browser reload rehydrates from database state, and successful stages remain durable checkpoints;
- old `context_path` response fields are transitional compatibility aliases whose value is a `mysql:` reference, never a filesystem path.

Physical files are reserved for documents and images that do not belong in normal database JSON columns:

```text
_Document/
  attachments/
    todo-chat/
    sharingan/
```

MySQL stores only the normalized project-relative storage path, original name, MIME type, byte size, SHA-256, status, and owning record keys. It must not store image data URLs, base64 image blobs, or absolute source-checkout paths. Downloads are served by authenticated project endpoints after current-user and current-project validation.

## Permission and security rules

- PHP, the browser, and AI workers must never execute `sudo`.
- Sudo passwords must never be stored in source, settings, JSON, logs, or database records.
- The installer does not create a bridge service account, shared bridge group, setgid exchange directory, ACL policy, or per-project user service.
- The coding engine receives an allowlisted current-project root resolved dynamically from the installation.
- A newly installed project must not read or write `/var/www/html/developer` or another installed project.
- Planning operations receive no source-file write permission.
- Coding operations require administrator confirmation and a saved todo scope.
- The Git Update Specialist operates only inside the dynamically resolved current-project repository and preserves unrelated user changes.
- Sharingan remains a surface feature rather than another engine. It uses the shared server adapter, preserves the current route and installed-project scope, and never elevates a User Portal session into Administrator or coding authority.
- All database writes use parameterized SQL, transaction boundaries, audit events, and direct read-back.
- A newly installed project must be usable from the browser without a post-install terminal command or manual workspace-path setting. Installing the versioned companion extension is a one-time desktop action, not a per-project repair.
- The web installer does not initialize `.git` as the Apache user. The one-time VS Code companion initializes missing Git metadata dynamically in the active generated workspace as the current desktop user, giving Codex a valid repository without sudo, groups, ACL repair, a manual command, or a hardcoded path.

## Timeout, retry, and recovery rules

- The browser never owns a long-running workflow.
- Create the persistent run before contacting the AI Bridge.
- Save the bridge request ID and active Codex AI Chat identity when the bridge provides them.
- Add heartbeat and lock-expiration timestamps.
- Give every model operation a bounded timeout.
- Retry only the failed chunk, normally no more than two or three attempts.
- Save successful chunks permanently before continuing.
- Resume from the first incomplete chunk after reload.
- Allow cancellation between chunks.
- Cache reusable results by source hash and request version.
- Reject stale results if upstream data changes.
- Display exact persisted progress and exact error codes after page reload.

## Consistency controls for chunked work

Chunking reduces prompt size and timeout risk but can create inconsistent terminology or conflicting decisions. Every stage must therefore use:

- one canonical project glossary;
- immutable requirement, module, phase, task, sub-task, todo, screen, API, and table IDs;
- shared global constraints;
- strict versioned JSON contracts;
- source hashes;
- deterministic merge rules;
- duplicate and relationship validation;
- one bounded final integration review.

## Phase Manager overhaul record

The Developer database record was reconciled with this contract on 2026-08-20 and contains:

```text
Phase: P3 - BuilderX Two-Engine AI Overhaul
Phase key: e4c915cd-f875-4f69-92e2-0fd44a210e45
Status: In Progress
Tasks: 10
Checklist items: 50
```

The phase title, summary, task wording, and checklists use the approved BuilderX AI Bridge and active Codex AI Chat architecture. The roadmap also classifies Sharingan as a shared three-surface feature rather than a third engine. Milestones 1-5 and the Sharingan lifecycle now have focused source, database, HTTP, build, reload, authorization, isolation, authenticated desktop, and responsive evidence. P3 remains `In Progress`: P3-T01 is `4/5`, P3-T02 through P3-T06 are `5/5`, P3-T07 is `4/5`, P3-T08 is `5/5`, P3-T09 is `2/5`, and P3-T10 is `5/5`. The dedicated Coding graphical runtime, clean-Git authorization, official installer-manifest refresh, genuine fresh database/install proof, and full fresh-release rollback remain pending.

## Proposed implementation order

1. Keep the approved Phase Manager overhaul record aligned with the required AI Bridge and Codex AI Chat dependency.
2. Keep `docs/project/rules.md` aligned with the required bridge runtime and Sharingan's three-surface, non-engine boundary.
3. Maintain and independently verify the persistent run, stage, chunk, and event foundation.
4. Maintain and re-verify the deterministic orchestrator and shared AI Bridge adapter under the amended Sharingan lifecycle.
5. Maintain Requirements Analysis as the first full bounded semantic-chunk Phase Builder workflow and re-run its contract tests before later workflow changes.
6. Maintain the migrated Narrative, Architecture, UI/UX, and Roadmap workflows behind the same persistent Planning Engine.
7. Maintain the isolated Phase Manager Coding Engine and its bounded Git Update stage; keep Git writes disabled without explicit approval.
8. Maintain Sharingan directly in the User Portal, Administrator Portal, and Phases surfaces through the same server-owned adapter, with route-correct capture context and least-privilege authorization.
9. Consolidate duplicate Phase Builder, Phase Manager, and Sharingan bridge lifecycle code behind the shared adapter without creating another engine.
10. Remove obsolete context/result paths and autonomous specialist-agent dispatch paths only after feature parity is verified.
11. Complete permission, timeout, cancellation, retry, reload, stale-source, Git dirty-worktree, Git approval, and Sharingan three-surface matrix tests.
12. Synchronize the verified Developer source into the clean installer template.
13. Perform a fresh installation under a different project-directory name and prove that its database, runtime, workspace, generated state, and Git scope are empty or correctly isolated.
14. Make `/var/www/html/developer` unavailable to the test installation and prove that the installed web UI, both engines, Sharingan, AI Bridge workspace resolution, MySQL exchange, document storage, database access, and Git scope still operate only within the installed project.

## Completion definition

The overhaul is complete only when:

- exactly two engines are active within Phase Builder and Phase Manager;
- no additional AI agent is dispatched;
- every long workflow is stage- and chunk-persistent;
- browser reload resumes from database state;
- all Phase Builder and Phase Manager AI requests use the approved AI Bridge and active Codex AI Chat;
- no request depends on Codex CLI, MCP, a BuilderX-maintained direct OpenAI API integration, or a hidden fallback;
- Sharingan operates directly on all three approved surfaces through the shared Bridge lifecycle without becoming another engine, provider, or autonomous agent;
- Sharingan preserves route and project context, captures the correct visible surface and selected element, and passes allowed/denied authorization and CSRF checks without granting User Portal users Administrator or coding privilege;
- permissions pass for both allowed and denied paths;
- all model results are validated before persistence;
- coding completion includes actual tests and read-back evidence;
- every source-writing Coding implementation has an intact server-created pre-write source checkpoint bound to its execution record and run request and reverified at final persistence;
- database-writing Coding completion remains blocked until a separately implemented server-created database recovery checkpoint can be verified; the source checkpoint must never be represented as database rollback protection;
- Git updates use the Coding Engine's bounded Git Update Specialist stage, preserve unrelated changes, and require explicit approval for remote or destructive operations;
- `/var/www/html/developer` is verified before installer synchronization;
- a fresh installation starts empty and cannot access an older project;
- no installed runtime file, compiled asset, configuration value, database value, browser-storage value, or symlink target depends on `/var/www/html/developer`;
- a deployed copy passes its web and worker lifecycle tests while `/var/www/html/developer` is unavailable.
