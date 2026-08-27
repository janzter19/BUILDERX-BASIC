# BuilderX and visible Codex Chat

BuilderX uses a single VS Code companion extension for visible Codex Chat.
Install the companion VSIX once from the BuilderX browser installer. No terminal
installation command is part of a project install.

When a BuilderX project is open in VS Code, the companion automatically:

1. derives the active workspace;
2. initializes missing Git metadata as the current desktop user;
3. checks that `tools/builderx-ai-job.php` belongs to that workspace;
4. verifies the project's MySQL context and job tables;
5. claims the queued job and sends its complete prompt to visible Codex Chat;
6. leaves result persistence to the MySQL helper and PHP transaction layer.

There is no `builderx-bridge.service`, user systemd unit, workspace override,
custom group, ACL, or project-specific bridge installer.

If the Phase Builder bridge indicator is not ready, open the intended project
folder in VS Code, confirm OpenAI Codex is signed in and visible, run
`Developer: Reload Window`, and select **Refresh details**. No path should be
entered in BuilderX.
