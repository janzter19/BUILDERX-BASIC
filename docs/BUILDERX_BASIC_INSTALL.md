# BUILDERX-BASIC installation guide

This repository is a reusable BuilderX web template for the Portal and
Administrator applications. It excludes the `_Android` application. Runtime
credentials are supplied on each target computer and are never committed.

## 1. Requirements

- Ubuntu/Debian server with Apache 2.4, PHP 8.2 or newer, and PHP extensions
  `mysqli`, `curl`, `mbstring`, `json`, and `openssl`.
- MySQL 8 or MariaDB 10.6 or newer.
- Node.js 20 or newer and npm for building the frontend.
- Firebase project with Firestore and Firebase Authentication enabled.
- A Firebase Admin SDK service-account JSON stored outside the web root with
  owner-only permissions.

## 2. Obtain the source

```bash
git clone https://github.com/janzter19/BUILDERX-BASIC.git /var/www/html/rbmsv4
cd /var/www/html/rbmsv4
```

Do not copy `_Android` into this installation. Do not copy `.env` or any
service-account JSON from another computer.

## 3. Create the database

Create an empty database and a least-privilege web account using a local root
or database administrator account. Replace the example values locally:

```sql
CREATE DATABASE rbmsv4 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rbmsv4_web'@'localhost' IDENTIFIED BY 'REPLACE_LOCALLY';
GRANT ALL PRIVILEGES ON rbmsv4.* TO 'rbmsv4_web'@'localhost';
FLUSH PRIVILEGES;
```

Import the committed schema snapshot:

```bash
mysql -u rbmsv4_web -p rbmsv4 < database/builderx-basic-schema.sql
```

The export is schema-only so this public repository does not publish live
users, passwords, login history, or other customer data. The application
initializes project-local configuration and runtime tables on first use.

## 4. Configure the installation

Copy the example environment file and replace values locally:

```bash
cp .env.example .env
chmod 600 .env
```

Set `BX_ADMIN_DEFAULT_PASSWORD` only in the local environment when provisioning an Administrator account. Use a strong installation-specific value; never place a real password in source control, documentation, or chat.

Set the database host, database name, database username, and database
password. Set the Firebase web configuration values for the target Firebase
project. Store the Admin SDK JSON outside the web root, for example:

```text
/etc/builderx-basic/firebase-service-account.json
```

Set `FIREBASE_SERVICE_ACCOUNT_PATH` and
`GOOGLE_APPLICATION_CREDENTIALS` to that path. Never put the JSON contents,
database password, sudo password, or API secret in Git, a browser field, or a
log.

The PHP runtime configuration is installation-local at
`phases/config.local.php`; create it from the project example and set the
database connection values there when the installation uses the PHP config
path instead of `.env`.

## 5. Build the Portal and Administrator frontend

```bash
cd frontend
npm ci
npm run build
cd ..
```

The generated frontend output is intentionally ignored by Git. Apache must
serve the project root and allow the existing `.htaccess` rules. Enable the
required PHP modules and reload Apache after changing server configuration.

## 6. Configure TRAVERSE

TRAVERSE is the server-side Firebase-to-MySQL projection service. It uses the
Firebase Admin SDK and never runs with browser credentials.

Install dependencies from the project root:

```bash
npm ci
```

Create a root-owned environment file outside the repository containing the
dedicated MySQL projection account and Firebase service-account path. Use the
sample unit in `deploy/systemd/traverse.service` and adjust only the absolute
installation path. Then:

```bash
sudo cp deploy/systemd/traverse.service /etc/systemd/system/traverse.service
sudo systemctl daemon-reload
sudo systemctl enable --now traverse.service
sudo systemctl status traverse.service
```

Add one row to `project_traverse_document` for each approved Firebase
collection. The table is collection configuration, not a document inventory:

```sql
INSERT INTO project_traverse_document
  (firebase_collection, traverse_status)
VALUES ('project_group', 'ACTIVE');
```

Restart TRAVERSE after adding a collection. Confirm startup reports
`traverse_collections_loaded`. The collection must have a validated contract
in `scripts/firebase-mysql-sync/registry.mjs`; unknown collections are
rejected instead of producing an unsafe table.

Useful operational commands:

```bash
sudo systemctl restart traverse.service
sudo journalctl -u traverse.service -f
sudo systemctl is-active traverse.service
```

## 7. Verify both applications

1. Open `/administrator/` and sign in with an explicitly authorized
   Administrator Firebase Auth account.
2. Confirm `/administrator/?tab=users` and `/administrator/?tab=groups` load.
3. Open the Portal sign-in route and confirm a normal project user is denied
   Administrator routes.
4. Create a test Firebase-first record, confirm Firebase read-back, then
   confirm the TRAVERSE queue reaches `ACKED` and MySQL read-back reaches
   `SYNCED`.
5. Check logs for normalized error codes only; never paste tokens or
   credentials into diagnostics.

## 8. Update procedure

```bash
git pull --ff-only
cd frontend && npm ci && npm run build
sudo systemctl restart traverse.service
sudo systemctl is-active traverse.service
```

Review schema and migration output before accepting a production update.
TRAVERSE creates verified backups before destructive schema repair and fails
closed on field/type/identity mismatch. Do not delete legacy tables or run a
backfill without a separate backup and rollback decision.

## 9. Troubleshooting

- `BuilderX Setup Required`: create `phases/config.local.php` or complete the
  environment database settings.
- `firebase_not_configured`: check the Admin SDK path, file permissions, and
  Firebase project ID without exposing the JSON contents.
- `collection_not_allowlisted`: add a reviewed contract to the registry, add
  the collection to `project_traverse_document`, and restart TRAVERSE.
- `RETRY_WAIT` or `DEAD_LETTER`: inspect the normalized service error and the
  queue/projection tables; do not manually mark a row `SYNCED`.

## Scope boundary

This repository is the Portal/Administrator web baseline. `_Android`, BuilderX
Phase Manager source, personal Codex memory, local credentials, live data,
uploads, logs, and runtime state are not part of the public release.
