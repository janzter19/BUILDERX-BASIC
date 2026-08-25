# Development UI/UX Rules

## AI runtime boundary

- Phase Builder and Phase Manager must use the shared BuilderX AI Bridge with the active Codex AI Chat as their only approved AI transport.
- Keep exactly two application engines: one Phase Builder Planning Engine and one Phase Manager Coding Engine. Specialist labels and chunks are bounded stages executed by the applicable engine, not separately dispatched agents.
- Persist run, stage, chunk, event, source-hash, request, result, retry, and exact failure state in the current installation's database before advancing the workflow.
- Resolve the workspace from the current installed project root. A deployed copy must not read, write, link to, or fall back to the Developer source or another installation.
- Do not implement, invoke, document as a required step, or add a fallback to the Codex CLI terminal UI or a command-line Codex workflow.
- Do not add MCP, a BuilderX-maintained direct OpenAI API integration, a hidden provider, or an automatic provider fallback.
- Do not dispatch additional autonomous AI agents or child AI requests. Deterministic application code owns stage order, validation, retry, cancellation, and persistence.
- Sharingan is a surface feature, not a third engine or autonomous agent. It may be changed only to operate directly within the current User Portal, Administrator Portal, and Phases route through the same server-owned BuilderX AI Bridge adapter and active Codex AI Chat lifecycle.
- Sharingan capture and annotation must preserve the visible route, selected element, and installed-project identity. Its browser code must not call the loopback Bridge or another provider directly.
- User Portal exposure must not grant Administrator, Planning Engine, Coding Engine, source-write, Git, or product-mutation authority. Enforce Administrator-only and privileged operations at the backend with the established session, CSRF validation, saved scope, transaction, audit, and direct read-back.

## Layout width and containment

- Use the full available width for the primary workspace; do not add arbitrary max-width constraints.
- Do not place a nested bordered box or card inside another bordered box or card.
- Only top-level main cards should carry a visible border, and main card headers should show a clear header divider.
- Main card headers must remain sticky when their content scrolls; place overflow on the card body/content, not the outer card or page column.
- When nested grouping is necessary, use spacing, a different surface/background color, or a separator without adding another border.
- Prefer the existing shadcn/ui surface tokens and layout primitives so hierarchy comes from spacing, typography, and contrast rather than stacked boxes.
- Follow the project-local `ui-ux-main` skill for the shared sidebar, header, full-width workspace, responsive columns, and sticky footer labels.
- All new persisted writes must follow the project-local `database-transaction` skill: ADODB, parameterized SQL, one complete create/update upsert, explicit transaction boundaries, write-result checks, audit logging, read-back verification, and server-backed UI rehydration.

## Tabs and controls

- Tab menus must fit their labels and button content; do not stretch tabs to fill the entire panel unless the layout explicitly requires equal-width controls.
- Add an appropriate existing icon to navigation items and action buttons whenever one is available.
- Place card-level tab menus in the card header, separate from the tab content area; when the content scrolls, keep the tab menu visible with a sticky header and an opaque surface background.
- When a tab card has a submit action, add a tab-scoped sticky footer with the tab context label on the left and a submit button on the right; keep it separate from the global main-layout footer.
- Follow the project-specific sticky-tab guidance in `docs/project/ui-ux-skills.md`.
- Use the project-local `ui-ux-modal` and `ui-ux-tabs` skills for modal and tab implementation/review.
- Use the project-local `ui-ux-form` skill for every form. After native validation succeeds, show a confirmation modal before any native navigation, React submit handler, or persisted action runs.
- Form modal footers must not include a Cancel button. Keep dismissal on the header close control and Escape, with the footer reserved for the left status label and right primary action.
- Use the project-local `brainstorming` skill when the user asks to brainstorm, refine direction, compare approaches, or choose between product/UI/database/phase options before implementation.
- After a confirmed form submission, show exactly one result: a dismissing success toast on success, or an accessible informational modal on failure. If a technical reason is available, keep it collapsed behind `View more`. Do not use a transient error toast for failed submissions.
- A persisted form is not complete until both create and update work, the committed row is read back, and the refreshed form displays the saved database values instead of fallback defaults.

## Phase Manager authentication

- Phase Manager and Phase Builder must reuse the Administrator Portal username/password through the shared `bx_login()` session; do not create a second credential store.
- Require an authenticated Administrator role before loading phase, task, or Phase Builder draft data, and enforce the same rule on every write action server-side.
- Provide sign-in and sign-out actions on the Phase Manager workspace while preserving the selected target or phase after authentication.

## Phase Builder data naming

- Phase Builder-generated table names must use the `phase_builder_` prefix followed by the normalized table name.
