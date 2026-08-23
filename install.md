# BuilderX browser installation

BuilderX projects are created from `http://localhost/_installer/`.

## Project installation

1. Open the installer in a browser.
2. Enter a new project name, folder, administrator, and database name.
3. Review and create the project.
4. Open the generated Administrator Portal or Phase Manager.

The installer creates a new empty database, registers only the requested first
administrator, initializes the Phase Manager schema, validates the fresh state,
and rolls back both the folder and database when installation fails.

No post-install terminal command is required. A generated project does not use
a project-bound bridge service, manual bridge path, custom Linux account,
custom group, or ACL provisioning script.

The web installer deliberately does not create a `.git` repository as Apache.
When the generated folder is first opened in VS Code, the BuilderX companion
initializes the local repository automatically as the current desktop user.
This gives Codex a valid workspace without an ownership repair, terminal
command, account, group, ACL, or sudo step.

## One-time VS Code setup

Download the BuilderX companion VSIX from the browser installer and install it
once through the VS Code Extensions interface. OpenAI Codex must also be
installed, signed in, and visible in VS Code.

The BuilderX companion follows the folder currently open in VS Code. Switching
projects does not require changing a service file or saved path.

## AI transport

Phase Builder and Phase Manager store the full AI context, queued jobs, stage
results, failures, and read-back state in the generated project's MySQL
database. The companion receives only a job identity, claims the complete
prompt through `tools/builderx-ai-job.php`, and delivers it to visible Codex
Chat. PHP validates the MySQL result before any application write.

Images are stored as physical files below `_Document/attachments/`. MySQL stores
only relative paths, MIME types, sizes, hashes, and lifecycle metadata.

## Source release maintenance

Installer application deployment and release publication are maintainer
operations, not end-user installation steps. They are deliberately absent from
the browser installer. A published release must pass PHP lint, frontend build,
MySQL transport tests, clean-template isolation, package checksum verification,
and a real fresh-install lifecycle before it replaces the active template.
