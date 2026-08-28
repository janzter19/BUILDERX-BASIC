# BUILDERX-BASIC

Reusable baseline for the RBMS Portal and Administrator applications, including the Firebase-first web flows and the TRAVERSE Firebase-to-MySQL projection service.

## Start here

Read the complete installation guide before running the application:

- [`docs/BUILDERX_BASIC_INSTALL.md`](docs/BUILDERX_BASIC_INSTALL.md)
- [`database/README.md`](database/README.md)

The repository includes a schema-only database export at `database/builderx-basic-schema.sql`. It contains no live rows, passwords, Firebase service-account keys, or `.env` values. Configure each installation with its own database credentials and Firebase project configuration.

## Scope

This baseline packages the current `/var/www/html/rbmsv4` application without the `_Android` folder. It includes project documentation and repository-local agent skills. Personal Codex memory, editor state, dependency caches, runtime logs, uploads, and local secrets are intentionally excluded.

## Security rules

Never commit `.env`, Firebase Admin SDK service-account JSON, private keys, database passwords, tokens, or production data. Use environment-specific configuration outside the public web root and apply least-privilege database permissions.

## Main components

- Portal web application
- Administrator web application
- Firebase Authentication and Firestore integration
- TRAVERSE background projection service
- MySQL schema and installation documentation

The database is the synchronized projection/read layer; Firebase remains the authoritative source for the Firebase-first workflows described in the project documentation.
