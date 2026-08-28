---
name: ui-ux-setting
description: Design, document, and implement BuilderX Administrator settings screens, setting fields, table ownership, save flow, and Firebase settings sync.
---

# UI/UX Setting

Use this skill whenever changing or reviewing the Administrator settings screen, settings tabs, settings fields, table ownership, Android settings, Media settings, or settings-to-Firebase sync.

## Required Reading

- Read `docs/project/ui-ux-setting.md` before changing settings UI or persistence.
- If the work touches image upload or viewer fields, also read `docs/project/ui-ux-media.md`.
- If the work writes settings, also use `database-transaction`.

## Core Rules

- Keep settings split by ownership:
  - Global settings: `builder_system_setting`
  - Android project settings: `project_setting`
  - Media project settings: `project_setting_media`
  - Android client app registry: `builder_android_client_app`
- Keep Firebase settings sync pointed at Firestore collection `project_setting`.
- Use the active `project_key` as the Firebase document id.
- Exclude secret settings from visible payloads and Firebase sync.
- Keep the settings UI as two parent cards: editable left panel and summary right panel, each with independent scroll bodies.
- Keep the submit button inside the left panel footer and require confirmation before save.

## Verification

- Run focused PHP lint and static settings tests after changes.
- Verify the served Administrator settings route.
- For sync changes, run a live `bx_sync_settings_to_firebase()` read-back when credentials are available and confirm `collection` is `project_setting`.
