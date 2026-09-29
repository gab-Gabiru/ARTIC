# Artic — MSSQL Full-Stack Repair

This package keeps the existing Artic UI while repairing the PHP backend for Microsoft SQL Server and completing the required prototype functions from the final-project specification.

## Stack

- Frontend: HTML/CSS/JavaScript SPA
- Backend: PHP 8.x
- Database: Microsoft SQL Server via PDO_SQLSRV
- Authentication: PHP sessions + `password_hash()` / `password_verify()` + CSRF token
- Integration: PHP API endpoints + persisted audit/integration/notification logs
- Development server: PHP built-in server

## Run on localhost

Do not open the PHP project with Live Server or by double-clicking `index.php`.

You have two supported local-host options:

### Option 1 — Apache/XAMPP

1. Put this project inside your Apache document root (for example `htdocs`).
2. Make sure the PHP installation used by Apache has `pdo_sqlsrv` enabled.
3. Open `http://localhost/Artic_MSSQL_Reliable_Fixed/setup.php`.
4. Enter the SQL Server instance, database, and credentials. The original Artic project uses `GABIRU\SQLEXPRESS03` / `artic` / `sa` by default.
5. Click **Test Connection**, then **Save + Install / Repair Database**. This creates `config.local.php` for you.
6. Open `http://localhost/Artic_MSSQL_Reliable_Fixed/` and confirm the app loads.
7. Visit `health.php` and confirm `"ready": true`.

### Option 2 — PHP built-in server

From the project folder run:

```cmd
php -S localhost:8000 -t .
```

Then open `http://localhost:8000/setup.php`, configure/test SQL Server, and install/repair the database. After setup, open `http://localhost:8000/`.

The included `start_artic.bat` also starts the built-in server and now keeps the site reachable even when `pdo_sqlsrv` is missing, so `setup.php` and the diagnostic page can explain the missing runtime dependency.

## SQL Server setup

The project now includes `setup.php`. From localhost it can execute `schema.sql` and `REPAIR_EXISTING_ARTIC_DB.sql` through PDO_SQLSRV, so you do not have to manually split `GO` batches.

If you prefer SSMS, you can still run the SQL scripts directly.

The PHP `pdo_sqlsrv` extension is required. The project cannot ship Microsoft's native driver DLLs, so the extension must be installed in your PHP runtime.

## Connection configuration

`db.php` uses this precedence:

1. Environment variables such as `ARTIC_DB_SERVER` and `ARTIC_DB_PASS`
2. `config.local.php`
3. Safe local-development defaults

Example:

```php
<?php
return [
    'server' => 'GABIRU\\SQLEXPRESS03',
    'database' => 'artic',
    'username' => 'sa',
    'password' => 'YOUR_SQL_SERVER_PASSWORD',
    'trust_server_certificate' => true,
    'trusted_connection' => false,
];
```

For Windows Authentication, set `trusted_connection` to `true` only when the PHP/Apache process has permission to connect to SQL Server.

Use `health.php` whenever localhost reports a connection or database error. It now reports both connection health and missing schema objects.

## Seed accounts

```text
Admin
Email: admin@artic.io
Password: admin123

Verified Artist
Email: sora@artic.io
Password: artist123

Verified Artist
Email: marcus@artic.io
Password: artist123

Unverified Artist
Email: nova@artic.io
Password: artist123

Client
Email: elena@gmail.com
Password: client123

Client
Email: dev@gamerstudio.com
Password: client123
```

The admin hash in the schema is a PHP bcrypt hash for `admin123`. Do not replace it with a SQL Server `HASHBYTES()` value because the application authenticates with PHP `password_verify()`.

## Useful checks

Open:

```text
http://localhost:8000/health.php
```

A healthy response includes `ok: true`, database `artic`, and PDO driver `sqlsrv`.

For the application API:

```text
http://localhost:8000/api/bootstrap.php
```

When not logged in, it should return successful JSON with public listings and no private commissions.

## Main API routes

- `POST api/auth.php?action=login`
- `POST api/auth.php?action=register`
- `POST api/auth.php?action=logout`
- `POST api/auth.php?action=verify_artist`
- `POST api/auth.php?action=change_password`
- `GET api/bootstrap.php`
- `GET api/listings.php`
- `POST api/listings.php?action=create`
- `POST api/commissions.php?action=create`
- `POST api/commissions.php?action=status`
- `POST api/commissions.php?action=pay_escrow`
- `POST api/commissions.php?action=release_escrow`
- `POST api/commissions.php?action=add_file`
- `POST api/commissions.php?action=add_comment`
- `GET api/notifications.php`
- `POST api/notifications.php?action=mark_read`
- `POST api/notifications.php?action=mark_all_read`
- `POST api/admin.php?action=verify_artist`
- `POST api/admin.php?action=generate_code`
- `POST api/admin.php?action=change_role`

## Security controls

- Passwords use PHP password hashing.
- Login uses server-side verification; passwords are never compared as plaintext in SQL.
- Prepared statements are used throughout.
- CSRF checks protect state-changing API requests.
- Role checks are enforced server-side.
- Commission access checks ensure only the assigned client/artist can act.
- Listing slot claims use a transaction and SQL Server update locking.
- Audit and integration logs record important workflow operations.
- File submissions accept only HTTP/HTTPS URLs.

## Specification alignment

The current Artic system matches the approved Digital Media Commission and Client Approval Portal theme. The core workflow is:

Client brief/request → artist acceptance → escrow simulation → production → WIP/final links → review/revision → delivery → escrow release → notifications/audit/integration logs.

See `docs/COMPLIANCE_MATRIX.md` for the specification traceability audit, `docs/API_ROUTE_MATRIX.md` for route/RBAC coverage, `docs/AUTHENTICATION_AUDIT.md` for the login repair, `docs/ARCHITECTURE_DECISION_RECORD.md` for the architecture decision, `docs/TEST_PLAN.md` for the required test coverage, and `docs/DEPLOYMENT_BACKUP_RECOVERY.md` for deployment/backup/recovery evidence steps.


### Messaging and request notifications
- Public artist storefronts now display the artist's tier listings correctly.
- A new commission request creates notifications for both the client who sent it and the artist who receives it.
- Clients and artists can exchange persistent direct messages from the Messages page or directly from a commission detail page.
- Message history is stored in SQL Server and each new message notifies the recipient.


## Existing database repair

For an existing Artic database, `REPAIR_EXISTING_ARTIC_DB.sql` now repairs all optional workflow tables used by messaging, notifications, audit logging, status history, comments, and file versioning. It is safe to run more than once.

The API also treats audit/notification/integration writes as side effects: the core commission/message/comment/payment event is committed first, so a logging table mismatch cannot create a false “failed” action after the main database record has already changed.

`setup.php` can execute the schema and repair batches locally through PDO_SQLSRV.

