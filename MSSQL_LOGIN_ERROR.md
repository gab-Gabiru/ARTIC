# MSSQL login error

The reported health check proves:

- PHP is serving the app.
- `pdo_sqlsrv` is loaded.
- The SQL Server instance is reachable.
- The failure is authentication: `SQLSTATE[28000] ... Login failed for user 'sa'`.

This is not a JavaScript/event problem and not a network discovery problem.

## Two supported fixes

### Option A — Windows Authentication (recommended for local development)

Open `setup.php`, check **Use Windows Authentication**, then test/save/install.

For `php -S localhost:3000`, PDO_SQLSRV can use the current Windows account with NULL username/password.

### Option B — SQL Server Authentication

Enable mixed mode and enable the `sa` login, then assign the correct password. In SSMS:

1. Server Properties → Security → **SQL Server and Windows Authentication mode**.
2. Restart the SQL Server service.
3. Security → Logins → `sa` → Properties → Status → **Login: Enabled**.
4. Set/confirm a strong `sa` password.
5. Put that password in `setup.php` and test again.

Microsoft documents that `sa` remains disabled after switching to mixed mode until explicitly enabled.

The application now also tries Windows Authentication automatically when local `sa` authentication fails, provided `allow_windows_fallback` is enabled.
