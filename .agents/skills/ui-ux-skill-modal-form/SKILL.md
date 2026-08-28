---
name: ui-ux-skill-modal-form
description: Standardize responsive form modals with a three-region layout, scrollable body, and persistent footer actions.
---

# Modal form standard

Use this rule for every persisted form opened in a dialog.

## Required structure

Every modal must contain exactly three regions:

1. Header: title, short description, and the discoverable close control.
2. Body: independently scrollable form/content area with consistent `p-6` inset.
3. Footer: sticky/anchored bottom action bar spanning the full modal width.

The modal itself must use `max-h-[calc(100dvh-2rem)]`, `w-[calc(100vw-2rem)]`, and an opaque `bg-popover` header/footer. The page behind the modal must not become the scroll container.

## Footer rule

The footer is mandatory for every form modal. The persistent status/context label stays on the left and the primary action (for example, Save, Create, or Submit) stays on the far right on desktop. Use `flex-row items-center justify-between`, `w-full`, `shrink-0`, `border-t`, and `m-0` when the dialog content uses `p-0`.

Do not put a Cancel button in a form footer. Dismissal uses the header close button and Escape; confirmation dialogs may use their own Cancel action.

## Form behavior

Keep the footer outside the body scroll region. Preserve native validation, CSRF, confirmation-before-submit, pending/disabled state, success feedback, and accessible labels. Do not change the underlying persistence contract while correcting modal layout.

## Verification

Check desktop and narrow widths, long content, keyboard/Escape close, focus return, validation failure, submit pending state, footer alignment, no horizontal overflow, and that the last body field has equal bottom breathing room.
