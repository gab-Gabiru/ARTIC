# Artic MSSQL Repair Report

## What was repaired

- Reworked SQL Server configuration so the app can run under Apache/XAMPP localhost or the PHP built-in server without depending on a CLI-only environment variable.
- Added `config.local.example.php` and support for `config.local.php`, environment variables, SQL Server Windows Authentication, and encrypted SQL Server connections with trusted certificates for local development.
- Added localhost-only `setup.php` to install `schema.sql` and repair an existing database with `REPAIR_EXISTING_ARTIC_DB.sql`.
- Rebuilt the existing-database repair script so it creates/repairs all workflow support tables and required indexes, including status history, comments, files/versioning, messages, notifications, audit logs, and integration logs.
- Added schema-health checking in `health.php` so missing SQL objects are reported instead of appearing as generic application errors.
- Hardened bootstrap queries so optional support tables cannot break the entire application when they are absent from an older database.
- Changed commission, payment, file, comment, and messaging writes so the primary event is committed before non-critical notification/audit/integration side effects. This prevents a successful SQL change from being reported as a failed request when only a logging side effect has a problem.
- Added transaction rollback protection where write operations can fail.
- Added the missing change-password front-end workflow and connected it to the existing password-change API.
- Added a notification "Mark all read" UI action and fixed message-thread read state refreshes.
- Made dynamic event handlers available through `window` so inline HTML event attributes work reliably when the SPA is loaded from localhost or an Apache subfolder.
- Replaced manual query-string parsing with `URLSearchParams`.
- Made the API base URL resolve from `document.baseURI`, so `/project/api/...` works when the project is under `htdocs` instead of only at web root.
- Added form-state protection for file upload and client-comment actions.
- Added clearer startup/boot failure links to `health.php` and `setup.php`.
- Updated local-server batch scripts and the README for repeatable localhost setup.

## Validation performed

- PHP syntax check: all PHP files passed `php -l`.
- JavaScript syntax check: `script.js` and `enhancements.js` passed `node --check`.
- Local HTTP smoke test: `index.php` returned HTTP 200 on the PHP development server.
- Local HTTP smoke test: `setup.php` returned HTTP 200 on the PHP development server.
- Health endpoint was executed. The current execution environment does not have Microsoft's `pdo_sqlsrv` extension enabled, so SQL Server integration cannot be exercised here; the health endpoint now reports that missing dependency explicitly rather than masking it.

## Required local prerequisite

The target Windows PHP runtime used by Apache/XAMPP must have the Microsoft `pdo_sqlsrv` extension installed and enabled. The SQL Server instance must also be running.

## First-run sequence

1. Copy `config.local.example.php` to `config.local.php` and enter the actual SQL Server connection settings.
2. Start Apache/XAMPP or run `start_artic.bat`.
3. Open `setup.php` once on localhost.
4. Open `health.php` and confirm `ok: true` and `ready: true`.
5. Open the main Artic page.
