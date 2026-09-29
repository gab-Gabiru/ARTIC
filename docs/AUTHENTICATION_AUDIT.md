# Authentication Audit and Repair

## Root causes found

The original MSSQL conversion had two separate authentication blockers:

1. The login query still used MySQL syntax (`LIMIT 1`) even though the database was Microsoft SQL Server. That can fail before password verification occurs.
2. The authentication implementation correctly uses PHP `password_verify()`. Therefore the `users.password_hash` value must be a PHP-compatible `PASSWORD_DEFAULT` hash (bcrypt in the current PHP environment), not plaintext and not a SQL Server `HASHBYTES()` value.

The repaired implementation uses SQL Server `TOP (1)` and keeps PHP `password_hash()` / `password_verify()`.

## Correct password storage flow

```text
User enters password
        |
        v
PHP login request
        |
        v
SELECT user by normalized email
        |
        v
password_verify(entered_password, stored_password_hash)
        |
   +----+----+
   |         |
 valid     invalid
   |         |
   v         v
session    HTTP 401
created
```

## Seed admin account

The supplied seed database contains a PHP bcrypt hash for the sample admin password. The hash is intentionally not duplicated here as a login credential.

To change a user's password, generate the PHP hash with the PHP runtime and update only `password_hash` in SQL Server. Do not use `HASHBYTES()` for the current authentication implementation.

## Session controls

- Session ID is regenerated after successful authentication.
- Session stores the authenticated `user_id` only.
- CSRF token is generated after login/registration.
- Protected API endpoints re-read the current user from the database.
- Role authorization is enforced on the server, not only in the UI.
- Logout destroys the session and clears the session cookie.
