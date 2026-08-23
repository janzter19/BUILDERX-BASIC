# Installation paths and permissions

The browser installer creates each project below the configured project root and
uses its folder name as the public path. It prepares source files and runtime
directories during installation so neither the desktop developer nor the web
application needs a post-install permission command.

BuilderX does not create a custom Linux account or group and does not install
ACLs. It does not install a project-specific systemd service. The installer
creates `_Document/attachments/` and required runtime folders before lifecycle
verification.

The generated project configuration supplies the same database to PHP and the
workspace-local MySQL job helper. Database credentials are never passed on a
command line or copied into an AI prompt.

The companion workspace is derived from the folder currently open in VS Code.
BuilderX does not store a manual path override.

For a production host, restrict operating-system access at the host boundary
after deployment without changing BuilderX's MySQL job contract. Production
hardening must not introduce a per-project bridge service or require an end user
to repair a generated project manually.
