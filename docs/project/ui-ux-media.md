# UI/UX Media Rules

## Image Uploaders

- Every image uploader must read `media_uploader_target_url` and `media_image_viewer_url` from project-scoped `project_setting_media` rows.
- Local development must configure `media_uploader_target_url` and `media_image_viewer_url` in `project_setting_media`; runtime code must not seed or fall back to a hardcoded host, LAN address, `_Media` URL, or viewer URL.
- Media endpoint settings must not be saved with `localhost`, `127.0.0.1`, or another loopback host; use the configured project media host so persisted image URLs are usable outside the local browser.
- If `media_uploader_target_url` is empty, disable upload controls and show a setup-required message.
- When a user selects, drops, or pastes an image, inspect its natural dimensions in the browser before upload.
- If the image width and height are both `1024px` or smaller, upload the original file unchanged.
- If either width or height is greater than `1024px`, resize the image locally before upload so the longest side is exactly `1024px` and the aspect ratio is preserved.
- The resized browser-created file is the file sent to `media_uploader_target_url`; the original local user file is never modified.
- The upload endpoint must return a full uploaded image URL, including scheme, host, and image path.
- Save the returned full uploaded image URL as the image source value in the form/database.
- Do not save only a filename, relative path, base64 value, or data URL as the persisted image source.
- Image upload forms must show an immediate preview for the selected/pasted image without exposing raw `_Media` URLs in visible UI.
- Persist the uploaded image URL as the source value, but use it only as the hidden database/form source value after upload.
- Inline thumbnails and saved-image previews must render through the configured PHP viewer URL when `media_image_viewer_url` is configured.
- Clicking a saved image preview should open an in-app image viewer modal, not a new browser tab. The modal must provide clickable `XS`, `S`, `M`, `L`, and `XL` size controls that update the PHP viewer `d` parameter.
- When displaying the image, use `media_image_viewer_url` when configured. Treat the configured value as the base PHP viewer URL, then append `d=<size>` and URL-encode the uploaded image URL into the `url=<uploaded image URL>` parameter unless the configured URL already provides placeholders.
- The PHP viewer must return actual image bytes for a single requested size token so it can be used directly as an `img` source. Multi-size requests may render an HTML gallery.
- If `media_image_viewer_url` is empty, display the uploaded image URL directly.
- The image viewer `d` parameter accepts size tokens separated by commas, dots, pipes, or spaces. Standard preview targets are `XS = 96 x 96px`, `S = 160 x 160px`, `M = 320 x 320px`, `L = 640 x 640px`, and `XL = 1024 x 1024px`.
- When multiple viewer tokens are supplied, for example `d=XS,S,M,L.XL`, render each requested preview size separately without changing the original uploaded image. A single-size thumbnail must be produced from the configured viewer URL with `d=XS` and the URL-encoded uploaded image source in the `url` parameter.

## Local Resize Examples

- `4000 x 3000` uploads as `1024 x 768`.
- `3000 x 4000` uploads as `768 x 1024`.
- `900 x 1200` uploads as `768 x 1024`.
- `800 x 600` uploads unchanged.
