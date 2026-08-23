# BuilderX companion 2.0.5

The BuilderX companion follows the first folder currently open in VS Code. It
hosts the local BuilderX endpoint, claims jobs from that project's MySQL-backed
helper, and delivers the complete prompt to visible Codex Chat.

It does not install a project service, write AI request/result files, save a
workspace override, or require a manual project path. Install the VSIX once and
reload VS Code after updating it. On first open, it initializes missing Git
metadata from the desktop process so Codex receives a valid desktop-owned
workspace without a terminal or ownership repair command. When Apache created
the public project root, the companion also registers only the currently open
workspace as a trusted Git directory so VS Code and Codex can use it without a
manual `safe.directory` command. It also creates the initial baseline commit as
the desktop user so visible Codex recognizes a complete repository immediately.
It also installs two narrowly matched Codex rules for the BuilderX `complete`
and `fail` helper commands. This lets a visible Codex task return its result to
the local MySQL job without a manual approval click or broad shell permission.
The generated job prompt uses ordinary local execution and never requests
sandbox escalation; after a successful helper read-back it does not retry the
completion command.
