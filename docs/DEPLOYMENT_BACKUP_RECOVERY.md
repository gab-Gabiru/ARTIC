# Artic Deployment, Backup, and Recovery Guide

## Deployment

1. Install PHP 8.x and enable `pdo_sqlsrv`.
2. Ensure Microsoft SQL Server is running and the `artic` database exists.
3. Run `schema.sql` in SSMS.
4. Start the application with `start_artic.bat` or:

```cmd
set ARTIC_DB_SERVER=GABIRU\SQLEXPRESS03
set ARTIC_DB_NAME=artic
set ARTIC_DB_USER=sa
set ARTIC_DB_PASS=YOUR_SQL_SERVER_PASSWORD
set ARTIC_DB_TRUST_SERVER_CERTIFICATE=1
php -S localhost:8000
```

5. Verify `http://localhost:8000/health.php` returns `ok: true`.
6. Open `http://localhost:8000/index.php`.

## Database backup

Use SQL Server backup from SSMS or sqlcmd. Example:

```sql
BACKUP DATABASE [artic]
TO DISK = N'C:\SQLBackups\artic_FULL.bak'
WITH INIT, COMPRESSION, CHECKSUM, STATS = 10;
```

The SQL Server service account must have write permission to the target backup directory.

## Source-code backup

Back up the complete project folder, especially:

- `api/`
- `schema.sql`
- `script.js`
- `style.css`
- `enhancements.js`
- `index.php`
- `db.php`
- `docs/`

Do not commit live database credentials.

## Recovery

Restore the database from the latest backup in SSMS, then verify:

```sql
RESTORE VERIFYONLY
FROM DISK = N'C:\SQLBackups\artic_FULL.bak';
```

After restoration, start the PHP server and test:

- `/health.php`
- `/api/bootstrap.php`
- admin login
- one client-to-artist commission workflow

## Required submission evidence

Capture screenshots of the actual backup operation and the recovery/verification result for the final project document. Source code alone does not constitute backup evidence.
