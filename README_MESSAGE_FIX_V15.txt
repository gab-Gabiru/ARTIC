V15 MESSAGE FIX

The SQLSTATE[07002] "COUNT field incorrect or syntax error" came from PDO_SQLSRV named parameters being reused in the same prepared statement.

Fixed:
- api/messages.php message GET query: sender/recipient now use distinct parameter names.
- api/messages.php mark_thread_read query: each occurrence has a distinct parameter name.
- api/bootstrap.php commission query: client/artist user parameters are distinct.

This is an ODBC parameter-count issue, not a SQL Server service failure.
