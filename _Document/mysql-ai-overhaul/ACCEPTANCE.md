# Acceptance contract

- Every Phase Builder workflow runs from its first deterministic stage through persistence using MySQL jobs and contexts.
- Every Phase Manager todo execution and rollback stage uses the same MySQL transport.
- Browser progress and result read-back come from MySQL; no runtime task/result JSON file is consulted.
- Image database rows contain only relative path metadata; image bytes exist on disk.
- A clean installer template contains no project data, no runtime AI rows, no local configuration, and no local credentials.
- A newly installed project opens in VS Code and uses the globally installed companion without a service install, path override, group command, ACL command, or permission repair command.
- The installer creates no Apache-owned `.git`; the companion automatically creates desktop-owned Git metadata, trusts only the currently open public workspace, and creates its initial baseline commit on first open, with no ownership repair or manual command.
- A failure found in a generated test project is corrected in Developer and the installer template, then verified by a new installation. Generated projects are never patched.
