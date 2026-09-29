# Artic Test Plan

The specification requires at least 8 functional, 5 integration, 5 error-handling, 3 security/access-control, and 1 end-to-end test scenario.

## Functional tests (8+)

| ID | Test | Expected result |
|---|---|---|
| F-01 | Login with valid admin credentials | Admin session created and Admin portal available |
| F-02 | Login with wrong password | HTTP 401 and clear invalid-credentials message |
| F-03 | Register client | Client account created with hashed password |
| F-04 | Register artist with valid invite | Verified artist created and invite consumed |
| F-05 | Artist creates listing | Listing persisted and visible in Browse |
| F-06 | Client requests commission | Commission persisted, slot claimed, artist notified |
| F-07 | Artist uploads WIP/final link | File record persisted, client notified |
| F-08 | Client posts feedback/revision | Comment/status/revision persisted and artist notified |
| F-09 | Client approves delivery | Commission enters delivered state |
| F-10 | Client releases escrow | Payment status becomes released |

## Integration tests (5+)

| ID | Test | Expected result |
|---|---|---|
| I-01 | Login API → SQL Server user lookup | Valid session returned |
| I-02 | Commission create → listing slot update | Both operations commit together |
| I-03 | File submission → notification + integration log | All related records created |
| I-04 | Status change → history + notification + audit log | All event records persisted |
| I-05 | Escrow release → payment status + logs | Database and logs agree |

## Error handling tests (5+)

| ID | Scenario | Expected result |
|---|---|---|
| E-01 | Missing email/password | 400 validation error |
| E-02 | Wrong password | 401 invalid credentials |
| E-03 | Invalid CSRF token | 403 |
| E-04 | Non-participant opens commission action | 403 |
| E-05 | Invalid file URL | 400 |
| E-06 | Full commission listing | 409 |
| E-07 | Duplicate email | 409 |

## Security/access-control tests (3+)

| ID | Scenario | Expected result |
|---|---|---|
| S-01 | Client calls admin endpoint | 403 |
| S-02 | Artist changes another artist's commission | 403 |
| S-03 | Request state-changing API without CSRF | 403 |
| S-04 | Submit SQL/HTML-like input | Stored/returned as data; prepared statements and escaping prevent injection/XSS |

## End-to-end scenario

Client login → browse listing → submit commission brief → artist accepts → client locks escrow simulation → artist submits WIP/final link → client reviews → revision if required → artist resubmits → client marks delivered → client releases escrow → notifications and audit/integration logs are visible.

Fill the `Result`, `Evidence`, and `Actual output` columns during execution for the final submission.
