# MSSQL connection fix

The application was using a machine-specific named instance (`GABIRU\\SQLEXPRESS03`) and, under localhost/Apache, could lose the environment variable containing the SQL Server password.

The repaired DB layer now:

- uses `localhost\\SQLEXPRESS03` as the local default;
- still accepts the original `GABIRU\\SQLEXPRESS03` setting;
- tries localhost, `.\\instance`, `(local)\\instance`, and the current host name for a named instance;
- accepts a fixed TCP port (`ARTIC_DB_PORT` / setup form);
- supports SQL Server Authentication and Windows Authentication;
- tries encrypted + trusted-certificate connectivity first and a local unencrypted fallback when TLS is the connection failure;
- reports the exact SQLSTATE/driver error and the connection profiles attempted;
- persists the successful settings in `config.local.php` so Apache localhost does not depend on the environment inherited by a command prompt.

Microsoft's current SQL Server PHP driver documentation confirms that `Server` may identify an instance or an IP/port, `TrustServerCertificate` controls certificate validation, and Windows Authentication uses the web server process identity. citeturn357969search0turn357969search2turn357969search3

## V5 localhost precedence fix

`config.local.php` now takes precedence over ARTIC_DB_* environment variables. This prevents an old Apache/system environment value from overriding a newly saved SQL Server password or server name. The startup screen also displays the actual database bootstrap error returned by the PHP API.
