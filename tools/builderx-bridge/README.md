# BuilderX MySQL companion

BuilderX 2 uses one VS Code companion extension for every installed project.
There is no project-bound Node service, systemd unit, saved bridge path, custom
Linux group, or ACL setup.

## Runtime flow

1. The BuilderX web application saves the complete phase context and a queued
   AI job in the project's MySQL database.
2. The companion derives the folder currently open in VS Code and exposes the
   loopback endpoint on `127.0.0.1:43127`. If the clean installation has no
   `.git`, the companion initializes it as the current desktop user before a
   Codex handoff. It automatically registers the currently open workspace as a
   Git safe directory when the public project root was created by Apache, then
   creates the initial desktop-owned baseline commit so Codex can attach the
   repository immediately.
3. The companion invokes `tools/builderx-ai-job.php claim <job-key>` from that
   current workspace and sends the returned prompt to visible Codex Chat.
4. Codex completes or fails the job through the same helper. The helper accepts
   JSON or a failure reason as the direct command's final argument and stores
   the terminal state in MySQL. The companion installs only the two matching
   owner-approved Codex helper rules. The job prompt uses ordinary local
   execution, never requests sandbox escalation, preserves the JSON as one
   shell-quoted argument, and never repeats a successful completion command.
5. PHP reads progress and the result directly from MySQL, validates the stage,
   performs the application transaction, and returns database read-back to the
   browser.

The helper never receives database credentials on the command line. It loads
the generated project's existing protected PHP configuration.

## Images

Images are physical files under `_Document/attachments/`. MySQL stores only
their relative path, MIME type, byte size, SHA-256 digest, and lifecycle state.

## Installation

Install `builderx-companion-2.0.5.vsix` once in VS Code. New BuilderX projects
do not require any terminal command. Opening another generated project in VS
Code automatically changes the companion workspace.
