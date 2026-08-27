---
name: ui-ux-media
description: Design and implement BuilderX image upload, paste, preview, and viewer behavior using project-scoped media settings. Use when adding or fixing image uploaders, avatars, group images, media previews, PHP image viewers, or saved image URLs.
---

# UI/UX Media

Use this skill for every BuilderX image upload or image viewing workflow.

## Source of Truth

- Read image media settings from `project_setting_media`, scoped by the active `project_key`.
- Required setting names:
  - `media_uploader_target_url`
  - `media_image_viewer_url`
- Do not hardcode media hosts, paths, client folders, upload URLs, viewer URLs, LAN IP addresses, `localhost`, or `_Media` paths in UI logic.
- System-level settings may seed or migrate defaults, but runtime upload and viewer controls must use the active project row from `project_setting_media`.
- Reject `localhost`, `127.0.0.1`, and other loopback hosts in saved media endpoint settings; they must point to the configured project media host.
- If `media_uploader_target_url` is empty, disable upload controls and show a setup-required message near the uploader.
- If `media_image_viewer_url` is empty, show the saved uploaded image URL directly.

## Upload Behavior

- Support file selection and clipboard paste when the UI exposes image upload.
- Before upload, inspect the image dimensions in the browser.
- If both width and height are `1024px` or smaller, upload the original file unchanged.
- If either dimension is larger than `1024px`, resize locally so the longest side is exactly `1024px` while preserving aspect ratio.
- The original user file must not be modified; only the browser-created upload file is resized.
- POST the image file to `media_uploader_target_url` with the relevant source metadata, such as `source_table` and `source_field`.
- The endpoint must return a full uploaded image URL including scheme, host, and path.
- Persist the returned full URL in the source table field. Never persist only a filename, relative path, data URL, or base64 value.

## Viewing Behavior

- Render saved thumbnails and previews through `media_image_viewer_url` when it is configured.
- Append or inject the uploaded image URL into the viewer URL, always URL-encoded.
- Support viewer URL placeholders in this order when present: `{{url}}`, `{url}`, `%s`, then existing `url=` query parameter.
- When a specific size is needed, apply `d=<size>` to the viewer URL without overwriting the uploaded image URL.
- Standard size tokens are `XS`, `S`, `M`, `L`, and `XL`.
- Clicking a saved image preview should open an in-app viewer modal with clickable size controls, not expose raw `_Media` paths as visible UI.
- The PHP viewer must return actual image bytes for a single requested size token so it works directly as an `<img src>`. Multi-size requests may render an HTML gallery.

## UI Rules

- Show an immediate local preview after file selection or paste, then replace it with the saved viewer URL after upload/read-back.
- Keep upload actions icon-forward and compact. Use clear labels or tooltips for upload, paste, and view actions.
- Keep validation and setup errors near the uploader. Do not hide upload failures in transient-only messages.
- Keep image source URLs in hidden form fields or payload data, not as prominent visible labels.
- Use the existing shadcn controls, modal conventions, and project tokens.

## Persistence and Verification

- For saves that store uploaded image URLs, also apply `database-transaction`.
- Verify create and update paths both preserve the returned full image URL.
- Read back the saved row after commit and confirm the visible preview uses the configured viewer URL.
- Verify the current project has active `project_setting_media` rows for both media settings.
- Run the relevant PHP lint, focused static media tests, frontend build, and route check after changes.
