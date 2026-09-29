# Artic Requirements Checklist — Final Project Specification

Source: Final Project Specification, Systems Integration and Architecture, Multimedia Arts and Animation Track.

## A. Minimum prototype/system requirements

| Requirement | Status | Evidence in repaired system |
|---|---|---|
| User Management / Login / RBAC | ✅ | `auth.php`, PHP sessions, CSRF, role checks, admin user/role management |
| Project / Production Module | ✅ | For the approved Digital Media Commission theme, each commission is the production record: client brief, quotation/price, workflow state, revisions, deliverables, approval, payment simulation, and final delivery are managed together |
| Asset / File Submission | ✅ | Artist deliverable-link submission in `commissions.php?action=add_file` |
| Version Tracking | ✅ | Persisted `commission_files` plus version number generated in bootstrap; revision counter and status history |
| Review and Approval Workflow | ✅ | Accepted, declined, in-progress, in-review, delivered, revision-request transitions |
| Comment / Feedback | ✅ | `commission_comments`, participant-only posting |
| Notification Log | ✅ | Persisted `notifications`, unread indicator, mark-read APIs |
| Dashboard / Report | ✅ | Client, artist, and admin dashboard KPIs; admin Reports tab |
| Audit Log | ✅ | `audit_logs` and admin Audit Trail tab |
| Integration Component | ✅ | REST-style PHP API, automated notifications, integration logs, workflow state transitions |

## B. Integration requirement

✅ The frontend, PHP API layer, SQL Server database, notification workflow, and integration/audit log modules are connected. The workflow also records external-storage-style HTTP links for deliverables.

## C. Suggested functional requirements

| FR | Status | Implementation |
|---|---|---|
| FR-001 Authorized users log in | ✅ | `auth.php?action=login` |
| FR-002 Creative production records are managed | ✅ | Commission records are the production/project unit for the selected Digital Media Commission theme and are created, tracked, revised, approved, and delivered |
| FR-003 Artists submit assets/file links | ✅ | `add_file` |
| FR-004 Asset versions are tracked | ✅ | File version counter + revision tracking |
| FR-005 Approve/reject/request revision | ✅ | Workflow transitions + delivery/revision actions |
| FR-006 Comments and feedback stored | ✅ | `commission_comments` |
| FR-007 Workflow status updates automatically | ✅ | Status transitions plus notification/audit/integration logging |
| FR-008 Important notification events are recorded | ✅ | `notifications` |
| FR-009 Dashboard summaries displayed | ✅ | User/artist/admin KPIs and reports |
| FR-010 Important user actions audited | ✅ | `audit_logs` |

## D. Suggested nonfunctional requirements

| NFR | Status | Evidence |
|---|---|---|
| NFR-001 Authorized access | ✅ | Server-side session + role checks |
| NFR-002 Assigned users only | ✅ | Commission participant authorization |
| NFR-003 Approval/rejection audited | ✅ | Workflow status audit events |
| NFR-004 Validate required fields | ✅ | Input helper validation, URL validation, range checks |
| NFR-005 Understandable dashboard | ✅ | Existing Artic UI + KPIs/reports |
| NFR-006 Clear errors | ✅ | Structured JSON API errors and frontend toast handling |
| NFR-007 Project/file data backed up | ⚠️ | Database/source can be backed up; actual backup evidence must be produced for submission |
| NFR-008 Technical documentation | ✅/⚠️ | README, compliance matrix, test plan, deployment notes, and backup/recovery guide are supplied; the final paper still needs the group-specific diagrams/screenshots/evidence |

## E. Required prototype screens

| Screen | Status | Current screen |
|---|---|---|
| Login Page | ✅ | Account view |
| Dashboard | ✅ | Client/Artist/Admin portals |
| Project List | ✅ | Browse/Open Slots |
| Project Details | ✅ | Commission detail |
| Asset or Task Submission Form | ✅ | Submit File Link |
| Asset Version / Revision History | ✅ | Deliverables + revision/status history |
| Review and Approval | ✅ | Commission action controls |
| Comment / Feedback | ✅ | Feedback & Discussion Log |
| Notification / Notification Log | ✅ | Notification dropdown + API |
| Audit Log | ✅ | Admin Audit Trail |
| Reports Page | ✅ | Admin Reports tab |
| User and Role Management | ✅ | Admin user table + role management |

## F. Evidence that still has to be captured by the group

The specification requires actual proof, not only implementation. The codebase covers the implementable requirements; the following submission evidence still has to be captured by the group:

1. API request and response.
2. Integration log showing successful event.
3. Database record after integration.
4. Functional test results: at least 8.
5. Integration test results: at least 5.
6. Error-handling test results: at least 5.
7. Security/access-control test results: at least 3.
8. One end-to-end scenario.
9. Deployment evidence.
10. Backup/recovery evidence.
11. Required architecture/process/DFD/sequence/use-case/deployment diagrams.

These evidence items cannot be truthfully marked as completed from source-code inspection alone.
