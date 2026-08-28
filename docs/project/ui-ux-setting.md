# UI/UX Settings Rules

This document is the source guide for the Administrator settings screen, its field ownership, database tables, save flow, and Firebase sync contract.

## Route And Screen

- Route: `/administrator/?tab=settings`
- Active tab parameter: `settings_group`
- Optional project scope parameter: `project_key`
- React view: `SystemSettingsView` in `frontend/src/App.tsx`
- PHP entry and POST handler: `administrator/index.php`
- Shared foundation helpers and schema: `app/foundation.php`

The settings screen uses one horizontal grouped tab menu followed by two parent cards:

- Left parent card: editable fields for the active settings tab.
- Right parent card: read-only summary for the same tab.
- Both cards have sticky headers and independent scrollable bodies.
- The submit action lives inside the left card footer and must open a confirmation modal before submit.
- The submit label is `Save & Sync` because successful save also syncs the public settings payload to Firebase.

Do not reintroduce top summary cards above the tabs. Keep the screen compact and task-focused.

## Tab Menu Grouping

The tab menu is a single row with vertical dividers between menu groups. Group labels use color to separate meaning:

- Core: `general`, `application`, `localization`, `contact`
- Access: `interface`, `login`, `security`
- Mobile: `android`, `media`, `firebase`
- Operations: `debug`, `ai`

Only groups with active settings appear. Unknown future groups are placed under `Additional`.

Preferred tab order:

1. `application`
2. `android`
3. `contact`
4. `general`
5. `interface`
6. `localization`
7. `login`
8. `security`
9. `media`
10. `debug`

## Table Ownership

Settings are deliberately split by ownership:

| Purpose | MySQL table | Scope key | Firebase payload section |
| --- | --- | --- | --- |
| Global non-project settings | `builder_system_setting` | none | `system` |
| Android project settings | `project_setting` | `project_key` | `project_settings` |
| Media project settings | `project_setting_media` | `project_key` | `project_media` |
| Android tenant client registry | `builder_android_client_app` | `client_app_code` | served by tenant endpoint, not the settings Firebase document |

Rules:

- Do not save Android settings in `builder_system_setting`.
- Do not save Media settings in `builder_system_setting`.
- Do not save Media settings in `project_setting`.
- Do not save Android settings in `project_setting_media`.
- Do not put secret settings into Firebase.
- The Firestore collection for settings sync is `project_setting`.
- The Firestore document id is the active `project_key`; fallback is `current` only when no project exists.

## Table Fields

`builder_system_setting` columns:

- `x_id`
- `setting_key`
- `setting_name`
- `setting_value`
- `setting_group`
- `is_secret`
- `setting_status`
- `created_at`
- `updated_at`

`project_setting` columns:

- `x_id`
- `setting_key`
- `project_key`
- `setting_name`
- `setting_value`
- `setting_group`
- `is_secret`
- `setting_status`
- `created_at`
- `updated_at`

Unique key: `(project_key, setting_name)`

`project_setting_media` columns:

- `x_id`
- `setting_key`
- `project_key`
- `setting_name`
- `setting_value`
- `setting_group`
- `is_secret`
- `setting_status`
- `created_at`
- `updated_at`

Unique key: `(project_key, setting_name)`

`builder_android_client_app` stores tenant app codes and Firebase client connection data:

- `client_app_code`
- `client_name`
- `firebase_project_id`
- `firebase_database_url`
- `firebase_firestore_database_id`
- `firebase_api_key`
- `firebase_app_id`
- `firebase_messaging_sender_id`
- `firebase_storage_bucket`
- `android_package_name`
- `apk_download_path`
- `banner_image_url`
- `login_background_image_url`
- `splash_screen_image_url_1`
- `splash_screen_image_url_2`
- `splash_screen_image_url_3`
- `geofence_latitude`
- `geofence_longitude`
- `geofence_max_radius_meters`
- release, geofence, offline, refresh, media, and status flags

This table is used by `/var/www/html/rbms.com/api/mobile/tenant-configuration/` when the Android app submits a Hospital Code such as `RBMS-VRP` or `RBMS-CAB`.

## Android Settings

Source table: `project_setting`

Seed helper: `bx_android_project_setting_defaults()`

Group: `android`

Fields:

- `android_app_package_name`
- `android_tenant_configuration_endpoint_url`
- `android_welcome_title`
- `android_welcome_description`
- `android_current_version_code`
- `android_min_supported_version_code`
- `android_force_update_enabled`
- `android_release_acknowledgement_required`
- `android_geofence_required`
- `android_geofence_latitude`
- `android_geofence_longitude`
- `android_geofence_max_radius_meters`
- `android_update_apk_download_path`
- `android_banner_image_url`
- `android_login_background_image_url`
- `android_splash_screen_image_url_1`
- `android_splash_screen_image_url_2`
- `android_splash_screen_image_url_3`
- `android_offline_queue_enabled`
- `android_offline_retry_interval_seconds`
- `android_dashboard_refresh_seconds`
- `android_media_upload_enabled`

Android tab sections:

- Tenant Bootstrap: Android welcome title, welcome description, package name, and tenant configuration endpoint.
- Release Gates And Assets: version code, minimum supported version, force update, release acknowledgement, geofence enablement, APK download path, Android banner URL, login background URL, and three splash image URL slots.
- Geofencing: latitude, longitude, and maximum radius used when Android geofence checks are enabled.
- Offline And Sync: offline queue, retry interval, dashboard refresh interval, and media upload flag.

Validation:

- Package name must look like a Java/Kotlin Android package.
- Tenant configuration endpoint must be HTTPS, or private-network HTTP for development.
- Welcome title is required and must be 120 characters or less.
- Welcome description is optional and must be 240 characters or less.
- APK path must be a root-relative `.apk` path or an HTTP(S) `.apk` URL.
- Banner, login background, and splash image URLs must be HTTP(S) URLs when present.
- Geofence latitude may be blank, or a numeric value between `-90` and `90`.
- Geofence longitude may be blank, or a numeric value between `-180` and `180`.
- Geofence max radius must be a whole number between `1` and `1000000` meters.
- Version codes must be positive whole numbers.
- Retry and refresh seconds must be between `5` and `86400`.
- Switch fields must save as `0` or `1`.

## Media Settings

Source table: `project_setting_media`

Seed helper: `bx_media_project_setting_defaults()`

Group: `media`

Fields:

- `media_uploader_target_url`
- `media_image_viewer_url`

Media tab sections:

- Media Endpoints: upload target used by uploader controls.
- Image Viewer: PHP viewer URL used to render uploaded images.

Rules:

- Media URLs must be full URLs.
- Upload controls must read these values from `project_setting_media` for the active project.
- Image upload and viewer behavior is documented in `docs/project/ui-ux-media.md`.

## Global Settings

Source table: `builder_system_setting`

Groups currently shown in the settings UI:

- `application`: `app_url`, `public_path`, `admin_path`, `system_path`
- `contact`: `contact_name`, `contact_email`, `contact_phone`, `contact_address`
- `general`: `software_name`, `software_description`, `version`
- `interface`: `admin_default_tab`, `sharingan_enabled`
- `localization`: `default_language`, `default_time_zone`, `default_currency`
- `login`: login and first-administrator page labels, descriptions, feature card text, and form labels
- `security`: session timeout, password rules, reset token minutes, recovery policy placeholders
- `debug`: debug visibility switches, allowed roles, and trace retention
- `firebase`: Firebase project settings are global, but secret fields must not be exposed in the visible form or Firebase settings document
- `ai`: Codex chat/session placeholders

Runtime payload excludes:

- `setting_group IN ('android', 'media')` from `builder_system_setting`
- `setting_name LIKE 'ui_%'`
- `template_presets`
- secret values

## Save Flow

POST action: `save_system_settings`

Hidden form fields:

- `csrf`
- `action=save_system_settings`
- `settings_group`
- `project_key`

Input names use the stable setting key:

```text
setting_values[<setting_key>]
```

Server flow:

1. Validate CSRF and administrator authorization before handling the action.
2. Read active setting metadata and current values from `builder_system_setting`, `project_setting`, and `project_setting_media`; this read does not seed or update MySQL.
3. Load active global settings, Android project settings, and media project settings within their defined scope.
4. Ignore unknown, secret, and `ui_` settings.
5. Validate each submitted value by setting name.
6. Apply validated changes to an in-memory Firebase payload.
7. Write `project_setting/{project_key}` through the Firebase Admin SDK and verify the document read-back.
8. Leave MySQL unchanged in the web request. TRAVERSE consumes the acknowledged Firebase document and projects it to the MySQL tables.
9. Redirect back to the same settings tab with Firebase/PENDING status.

The settings form uses one explicit confirmation only: `Save & Sync` confirms the Firebase write. The form opts out of the global generic submit confirmation so the request is submitted once and the shell loading indicator clears on the redirect.

The web save path must not update the settings tables directly. MySQL becomes current after TRAVERSE projection and exact read-back; a failed Firebase acknowledgement must not be reported as saved.

Current compatibility gate: the existing Firebase writer stores one aggregate document at `project_setting/{project_key}`, while the MySQL settings tables are row-oriented (`builder_system_setting`, `project_setting`, and `project_setting_media`). TRAVERSE must receive an explicitly reviewed unpacking contract before settings can be declared fully projected back to MySQL. Do not add `project_setting` to the generic TRAVERSE registry as a row-level mapping without that contract; doing so would create incorrect columns or lose setting rows.

## Firebase Sync

PHP helper: `bx_sync_settings_to_firebase()`

Payload helper: `bx_settings_firebase_payload()`

Node script: `scripts/firebase-settings-sync.mjs`

Firestore destination:

```text
project_setting/{project_key}
```

The synced document includes:

- `document_key`
- `firebase_collection`
- `server_synced_at`
- Firebase read-back is required before the request is reported successful.
- `project`
- `system`
- `project_settings`
- `project_media`
- `summary`

The Firebase write performs a document read-back before returning success. The current web path does not perform a direct MySQL settings mutation.

`system`, `project_settings`, and `project_media` each include:

- `source_table`
- `groups`
- `count`

Do not rename the Firebase collection back to `builder_settings`. The current contract is `project_setting`.

## Tenant Client App Flow

The Android app is one app binary, but it can bind to different Firebase projects by submitting a client app code.

Examples:

- `RBMS-VRP`
- `RBMS-CAB`

Flow:

1. Android first screen asks for Hospital Code.
2. Android posts `client_app_code` to `android_tenant_configuration_endpoint_url`.
3. Endpoint reads `builder_android_client_app` by `client_app_code`.
4. Endpoint returns Firebase project id, database URL, app id, sender id, storage bucket, APK path, splash URLs, and runtime flags.
5. Android stores the tenant binding locally and uses it for dashboard, media, and offline queue behavior.

Keep this client registry separate from the Settings tab tables. The Settings tab controls project defaults; `builder_android_client_app` controls per-client Firebase binding.

## Verification Checklist

After changing settings UI, tables, save behavior, or Firebase sync:

- Run PHP lint for `app/foundation.php`, `administrator/index.php`, and any changed PHP tests.
- Run `node --check scripts/firebase-settings-sync.mjs` when sync script changes.
- Run focused static checks:
  - `php tests/android-app-settings-config-static.php`
  - `php tests/admin-group-image-upload-static.php`
  - `php tests/android-tenant-configuration-endpoint-static.php`
  - `php tests/firebase-settings-sync-static.php`
- Load `/administrator/?tab=settings&settings_group=android` and `/administrator/?tab=settings&settings_group=media`.
- Confirm the Save button remains inside the left parent card.
- Confirm the right parent card summary updates from active tab data.
- Confirm the Firebase sync response reports collection `project_setting`.
