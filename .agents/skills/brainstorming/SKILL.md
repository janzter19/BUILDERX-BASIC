---
name: brainstorming
description: Explore BuilderX product, UI, database, or phase decisions before implementation by comparing practical options, tradeoffs, risks, and a recommended next action. Use when the user asks to brainstorm, refine direction, or choose an approach; do not use for direct narrow fixes.
---

# Brainstorming

Use this skill when a BuilderX request needs product thinking before source, database, Git, or generated phase data changes.

## Workflow

- Restate the specific decision, uncertainty, or workflow being explored.
- Generate 2-4 concrete options. For each option, include the user-facing outcome, implementation impact, risks, and when it fits.
- Prefer options that respect the current BuilderX project boundaries, Administrator workflows, Phase Manager context, project-level tables, and UI rules.
- Call out dependencies such as database tables, migrations, permissions, phase data, administrator settings, or Git workflow when they affect feasibility.
- End with one recommended direction and the smallest next action needed to validate or implement it.
- Do not edit source, database, Git, or generated phase data during brainstorming unless the user explicitly switches from ideation to implementation.

## Output Shape

- Keep the answer concise enough to compare choices quickly.
- Use short headings or bullets when comparing options.
- State assumptions separately from confirmed facts when the current workspace has not been inspected.
