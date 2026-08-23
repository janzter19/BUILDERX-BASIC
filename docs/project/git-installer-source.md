# Git Source and Installer Releases

The authoritative installation, Ubuntu bootstrap, Git release, template publication, fresh-install acceptance, migration boundary, and dedicated Coding identity instructions are consolidated in [`install.md`](../../install.md).

The short contract is:

```text
clean committed Developer revision
  -> scripts/build-installer-release.sh
  -> SHA-256-bound archive and manifest
  -> scripts/publish-installer-template.sh
  -> local-only browser installer
  -> new folder + new database
  -> permission finalizer + visible Codex Bridge helper
  -> different-name isolation and lifecycle proof
```

`deploy/installer` is the Git source for the external application at `/var/www/html/_installer`. `scripts/deploy-installer-application.sh` synchronizes only those versioned application files and preserves protected configuration, templates, releases, projects, and databases.

Never refresh a template from an uncommitted workspace, forge a source manifest, reuse an existing project database, or treat Git as a runtime-data backup.
