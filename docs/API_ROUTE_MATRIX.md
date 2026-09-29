# API and Access-Control Matrix

| Route | Method | Access | Purpose |
|---|---|---|---|
| `auth.php?action=login` | POST | Public | Authenticate existing user |
| `auth.php?action=register` | POST | Public | Create client/artist account |
| `auth.php?action=logout` | POST | Authenticated | Destroy session |
| `auth.php?action=verify_artist` | POST | Artist | Redeem verification code |
| `auth.php?action=change_password` | POST | Authenticated | Change own password |
| `bootstrap.php` | GET | Public/Authenticated | Load current application state |
| `listings.php` | GET | Public | Browse/search listings |
| `listings.php?action=create` | POST | Artist | Publish listing |
| `commissions.php?action=create` | POST | Client | Create commission request |
| `commissions.php?action=status` | POST | Assigned artist/client | Controlled workflow transition |
| `commissions.php?action=pay_escrow` | POST | Assigned client | Lock simulated escrow |
| `commissions.php?action=release_escrow` | POST | Assigned client | Release simulated escrow |
| `commissions.php?action=add_file` | POST | Assigned artist | Add WIP/final deliverable link and version |
| `commissions.php?action=add_comment` | POST | Assigned participant | Add feedback/comment |
| `notifications.php` | GET | Authenticated | Read notification log |
| `messages.php` | GET | Authenticated client/artist | Load direct commission conversations |
| `messages.php?action=send` | POST | Assigned client/artist | Send a private commission message and notify the recipient |
| `messages.php?action=mark_thread_read` | POST | Assigned client/artist | Mark a conversation as read |
| `notifications.php?action=mark_read` | POST | Authenticated | Mark own notification read |
| `notifications.php?action=mark_all_read` | POST | Authenticated | Mark all own notifications read |
| `admin.php?action=verify_artist` | POST | Admin | Manually verify artist |
| `admin.php?action=generate_code` | POST | Admin | Generate master verification code |
| `admin.php?action=change_role` | POST | Admin | Manage user role |
| `health.php` | GET | Local deployment check | Verify SQL Server connectivity |

All state-changing application routes require the session CSRF token in the JSON body and enforce role/ownership rules server-side.

