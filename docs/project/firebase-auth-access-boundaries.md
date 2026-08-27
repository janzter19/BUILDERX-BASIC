# Firebase Auth access boundaries

## Administrator and User Portal contract

Firebase Authentication is the identity authority for both surfaces, but the
surfaces are separate security audiences:

- Administrator uses the `rbms-administrator` audience and requires an explicit
  active Administrator role plus the explicit `builder_user.firebase_uid`
  mapping. The `admin_` email prefix, email domain, and UID alone are never
  authorization signals.
- User Portal uses the `rbms-portal` audience and resolves the Portal login
  alias through the active `project_user` projection before Firebase Auth
  sign-in. After token verification, Portal denies any active Administrator
  role, system-scoped Administrator permission, Administrators group
  membership, or Administrator session context. It does not reuse the
  Administrator session or grant Administrator access from an email prefix or
  Firebase claim alone.
- The browser sends the Firebase ID token and CSRF token to the appropriate
  same-origin handoff. Passwords and password hashes are not sent to or
  verified by PHP/MySQL.
- Server authorization remains responsible for active status, Firebase UID
  mapping, tenant/project scope, role/group checks, and session audience.
  `rbms-portal` sessions must not satisfy Administrator authentication checks.
- Portal and Administrator use separate PHP session cookie names
  (`RBMS_PORTAL_SESSION` and `RBMS_ADMIN_SESSION`) so signing in or out of one
  surface does not replace, inherit, or invalidate the other surface's
  browser session.
- Logout and disabled/deleted-account handling must clear the matching server
  session and client Firebase state. Claims or audience changes require an
  explicit server-side authorization read-back; email naming conventions are
  insufficient.

Administrator login implementation and verification are Administrator-owned.
Portal login implementation and verification are Portal-owned. The shared
`frontend/src/App.tsx` may contain both surfaces, but changes must preserve
both routes and their separate session boundaries.

## Verified Administrator login flow

Administrator login accepts a friendly `user_login`, such as `admin`, but does
not use that value as a Firebase credential. The server resolves the active
`project_user` row and reads `user_auth_email`. If that profile email is stale
or the profile projection is temporarily absent, the server resolves the email
from the explicitly mapped active Administrator `builder_user.firebase_uid`
through Firebase Admin SDK. The browser then uses that Firebase Auth email
with the password; the password never enters the PHP request.

The server receives only the Firebase ID token and CSRF token. It verifies the
token, checks the mapped Administrator role and status, creates the
`rbms-administrator` session, and rejects Portal sessions. A syntactically
valid profile email is not assumed to be a real Auth account.

The previous failure was caused by a valid-looking but stale
`project_user.user_auth_email`, plus an obsolete query using `user_deleted_at`
where the compatibility schema uses `user_deleted`. The resolver now uses the
actual Firebase Auth email for the explicit Administrator UID mapping.

Verification evidence on 2026-08-27: the resolver returned HTTP 200, Firebase
Auth accepted the approved test credential, the ID-token handoff succeeded,
and the browser reached `/administrator/` and rendered the Users page.
Credentials and token values are intentionally omitted.

The verified auth path now invokes the Firebase-first activity-history and
project-user telemetry writers for successful login/logout events. Failed
Firebase credential attempts record only a safe error code and username when a
project-user mapping is available.

## Login-history implementation gate

The verified authentication flow above is a prerequisite for login-history
logging. The planned event write is Firebase-first to
`project_user_login_history`, followed by Master Sync projection and exact
MySQL read-back. The event document uses a real Firebase document ID as
`user_login_history_key`, `user_*` event fields, Firebase server timestamps,
and PENDING MySQL lifecycle metadata. This document records the contract and
verified login flow. The new history canary still requires a real login/logout
event followed by queue processing and MySQL read-back evidence.
