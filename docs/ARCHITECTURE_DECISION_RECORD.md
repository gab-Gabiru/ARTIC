# Architecture Decision Record — Artic

## Decision

Use a layered architecture with a browser SPA, a PHP API/application layer, and Microsoft SQL Server as the system of record.

Use API-based integration combined with workflow automation inside the application. Commission status changes trigger notification, audit-log, and integration-log writes in the same transactional workflow.

## Why this matches the selected project theme

Artic is implemented as a Digital Media Commission and Client Approval Portal. The commission record acts as the production/project unit and stores the brief, pricing, participants, workflow state, revisions, deliverables, comments, approvals, and simulated escrow state.

## Integration flow

```text
Artic SPA (HTML/CSS/JS)
        |
        | JSON API requests
        v
PHP API / Application Layer
        |
        +---- Authentication + RBAC + CSRF
        |
        +---- Commission workflow
        |       |
        |       +---- Notification records
        |       +---- Audit records
        |       +---- Integration records
        |
        v
Microsoft SQL Server
        |
        +---- users
        +---- listings
        +---- commissions
        +---- commission_files
        +---- commission_comments
        +---- commission_status_history
        +---- notifications
        +---- audit_logs
        +---- integration_logs
```

## Consequences

Positive:
- Clear separation of presentation, business rules, and persistence.
- Server-side authorization prevents UI-only access control bypasses.
- SQL Server transactions protect commission slot allocation and workflow changes.
- Audit and integration logs are persisted with application data.

Trade-offs:
- The current prototype is a single PHP application rather than independent microservices.
- External storage is represented by validated HTTPS links rather than a full object-storage service.
- Payment is an internal simulation and is not a real payment gateway.
