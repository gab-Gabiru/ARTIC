/* ============================================================
   ARTIC EXISTING-DATABASE REPAIR
   Microsoft SQL Server / T-SQL
   Safe to run repeatedly after the base Artic database exists.
   ============================================================ */

IF DB_ID(N'artic') IS NULL
BEGIN
    THROW 50001, 'Database [artic] does not exist. Run schema.sql or localhost/setup.php first.', 1;
END
GO

USE artic;
GO

/* ------------------------------------------------------------
   ACCOUNT PROFILE MEDIA
   ------------------------------------------------------------ */
IF COL_LENGTH(N'dbo.users', N'profile_image_url') IS NULL
    ALTER TABLE dbo.users ADD profile_image_url NVARCHAR(2048) NULL;
GO
IF COL_LENGTH(N'dbo.users', N'profile_image_name') IS NULL
    ALTER TABLE dbo.users ADD profile_image_name NVARCHAR(255) NULL;
GO
IF COL_LENGTH(N'dbo.users', N'profile_image_mime') IS NULL
    ALTER TABLE dbo.users ADD profile_image_mime NVARCHAR(120) NULL;
GO
IF COL_LENGTH(N'dbo.users', N'profile_image_size') IS NULL
    ALTER TABLE dbo.users ADD profile_image_size BIGINT NULL;
GO

/* ------------------------------------------------------------
   COMMISSION STATUS HISTORY
   ------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.commission_status_history', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.commission_status_history
    (
        id INT IDENTITY(1,1) NOT NULL,
        commission_id INT NOT NULL,
        from_status NVARCHAR(20) NULL,
        to_status NVARCHAR(20) NOT NULL,
        actor_id INT NULL,
        note NVARCHAR(500) NOT NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_repair_status_history_created_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_repair_commission_status_history PRIMARY KEY (id),
        CONSTRAINT FK_repair_status_history_commission FOREIGN KEY (commission_id) REFERENCES dbo.commissions(id) ON DELETE CASCADE ON UPDATE NO ACTION,
        CONSTRAINT FK_repair_status_history_actor FOREIGN KEY (actor_id) REFERENCES dbo.users(id) ON DELETE SET NULL ON UPDATE NO ACTION,
        CONSTRAINT CK_repair_status_history_from CHECK (from_status IS NULL OR from_status IN (N'requested', N'accepted', N'declined', N'cancelled', N'inprogress', N'inreview', N'delivered')),
        CONSTRAINT CK_repair_status_history_to CHECK (to_status IN (N'requested', N'accepted', N'declined', N'cancelled', N'inprogress', N'inreview', N'delivered'))
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_status_history_commission_created' AND object_id = OBJECT_ID(N'dbo.commission_status_history'))
    CREATE INDEX IX_status_history_commission_created ON dbo.commission_status_history(commission_id, created_at);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_status_history_actor' AND object_id = OBJECT_ID(N'dbo.commission_status_history'))
    CREATE INDEX IX_status_history_actor ON dbo.commission_status_history(actor_id);
GO

/* ------------------------------------------------------------
   COMMISSION FILES + VERSION COMPATIBILITY
   ------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.commission_files', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.commission_files
    (
        id INT IDENTITY(1,1) NOT NULL,
        commission_id INT NOT NULL,
        type NVARCHAR(10) NOT NULL,
        label NVARCHAR(180) NOT NULL,
        url NVARCHAR(2048) NOT NULL,
        added_by INT NOT NULL,
        version_no INT NOT NULL CONSTRAINT DF_repair_commission_files_version_no DEFAULT 1,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_repair_commission_files_created_at DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_repair_commission_files_updated_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_repair_commission_files PRIMARY KEY (id),
        CONSTRAINT FK_repair_commission_files_commission FOREIGN KEY (commission_id) REFERENCES dbo.commissions(id) ON DELETE CASCADE ON UPDATE NO ACTION,
        CONSTRAINT FK_repair_commission_files_user FOREIGN KEY (added_by) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT CK_repair_commission_files_type CHECK (type IN (N'wip', N'final'))
    );
END
GO

IF COL_LENGTH(N'dbo.commission_files', N'version_no') IS NULL
BEGIN
    ALTER TABLE dbo.commission_files
        ADD version_no INT NOT NULL CONSTRAINT DF_repair_commission_files_version_no_legacy DEFAULT 1 WITH VALUES;
END
GO

;WITH RankedFiles AS
(
    SELECT id,
           ROW_NUMBER() OVER (PARTITION BY commission_id ORDER BY created_at ASC, id ASC) AS rn
    FROM dbo.commission_files
)
UPDATE f
SET version_no = r.rn
FROM dbo.commission_files AS f
INNER JOIN RankedFiles AS r ON r.id = f.id;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_commission_files_version' AND object_id = OBJECT_ID(N'dbo.commission_files'))
BEGIN
    CREATE UNIQUE INDEX UX_commission_files_version
    ON dbo.commission_files(commission_id, version_no);
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_commission_files_commission_created' AND object_id = OBJECT_ID(N'dbo.commission_files'))
    CREATE INDEX IX_commission_files_commission_created ON dbo.commission_files(commission_id, created_at);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_commission_files_added_by' AND object_id = OBJECT_ID(N'dbo.commission_files'))
    CREATE INDEX IX_commission_files_added_by ON dbo.commission_files(added_by);
GO

/* ------------------------------------------------------------
   COMMISSION COMMENTS
   ------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.commission_comments', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.commission_comments
    (
        id INT IDENTITY(1,1) NOT NULL,
        commission_id INT NOT NULL,
        author_id INT NOT NULL,
        [text] NVARCHAR(MAX) NOT NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_repair_commission_comments_created_at DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_repair_commission_comments_updated_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_repair_commission_comments PRIMARY KEY (id),
        CONSTRAINT FK_repair_commission_comments_commission FOREIGN KEY (commission_id) REFERENCES dbo.commissions(id) ON DELETE CASCADE ON UPDATE NO ACTION,
        CONSTRAINT FK_repair_commission_comments_author FOREIGN KEY (author_id) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_commission_comments_commission_created' AND object_id = OBJECT_ID(N'dbo.commission_comments'))
    CREATE INDEX IX_commission_comments_commission_created ON dbo.commission_comments(commission_id, created_at);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_commission_comments_author' AND object_id = OBJECT_ID(N'dbo.commission_comments'))
    CREATE INDEX IX_commission_comments_author ON dbo.commission_comments(author_id);
GO

/* ------------------------------------------------------------
   COMMISSION MESSAGES
   ------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.commission_messages', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.commission_messages
    (
        id BIGINT IDENTITY(1,1) NOT NULL,
        commission_id INT NOT NULL,
        sender_id INT NOT NULL,
        recipient_id INT NOT NULL,
        [message] NVARCHAR(MAX) NOT NULL,
        sent_at DATETIME2(0) NOT NULL CONSTRAINT DF_repair_commission_messages_sent_at DEFAULT SYSUTCDATETIME(),
        read_at DATETIME2(0) NULL,
        CONSTRAINT PK_repair_commission_messages PRIMARY KEY (id),
        CONSTRAINT FK_repair_commission_messages_commission FOREIGN KEY (commission_id) REFERENCES dbo.commissions(id) ON DELETE CASCADE ON UPDATE NO ACTION,
        CONSTRAINT FK_repair_commission_messages_sender FOREIGN KEY (sender_id) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT FK_repair_commission_messages_recipient FOREIGN KEY (recipient_id) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT CK_repair_commission_messages_participants CHECK (sender_id <> recipient_id)
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_commission_messages_thread' AND object_id = OBJECT_ID(N'dbo.commission_messages'))
    CREATE INDEX IX_commission_messages_thread ON dbo.commission_messages(commission_id, sent_at, id);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_commission_messages_recipient_read' AND object_id = OBJECT_ID(N'dbo.commission_messages'))
    CREATE INDEX IX_commission_messages_recipient_read ON dbo.commission_messages(recipient_id, read_at, sent_at);
GO

IF COL_LENGTH(N'dbo.commission_messages', N'attachment_url') IS NULL
BEGIN
    ALTER TABLE dbo.commission_messages ADD attachment_url NVARCHAR(2048) NULL;
END
GO

IF COL_LENGTH(N'dbo.commission_messages', N'attachment_name') IS NULL
BEGIN
    ALTER TABLE dbo.commission_messages ADD attachment_name NVARCHAR(255) NULL;
END
GO

IF COL_LENGTH(N'dbo.commission_messages', N'attachment_mime') IS NULL
BEGIN
    ALTER TABLE dbo.commission_messages ADD attachment_mime NVARCHAR(120) NULL;
END
GO

IF COL_LENGTH(N'dbo.commission_messages', N'attachment_size') IS NULL
BEGIN
    ALTER TABLE dbo.commission_messages ADD attachment_size BIGINT NULL;
END
GO

/* ------------------------------------------------------------
   NOTIFICATIONS
   ------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.notifications', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.notifications
    (
        id INT IDENTITY(1,1) NOT NULL,
        user_id INT NOT NULL,
        commission_id INT NULL,
        message NVARCHAR(500) NOT NULL,
        is_read BIT NOT NULL CONSTRAINT DF_repair_notifications_is_read DEFAULT 0,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_repair_notifications_created_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_repair_notifications PRIMARY KEY (id),
        CONSTRAINT FK_repair_notifications_user FOREIGN KEY (user_id) REFERENCES dbo.users(id) ON DELETE CASCADE ON UPDATE NO ACTION,
        CONSTRAINT FK_repair_notifications_commission FOREIGN KEY (commission_id) REFERENCES dbo.commissions(id) ON DELETE SET NULL ON UPDATE NO ACTION
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_notifications_user_read_created' AND object_id = OBJECT_ID(N'dbo.notifications'))
    CREATE INDEX IX_notifications_user_read_created ON dbo.notifications(user_id, is_read, created_at);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_notifications_commission' AND object_id = OBJECT_ID(N'dbo.notifications'))
    CREATE INDEX IX_notifications_commission ON dbo.notifications(commission_id);
GO

/* ------------------------------------------------------------
   AUDIT LOGS
   ------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.audit_logs', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.audit_logs
    (
        id BIGINT IDENTITY(1,1) NOT NULL,
        actor_user_id INT NULL,
        actor_name NVARCHAR(100) NOT NULL,
        action NVARCHAR(160) NOT NULL,
        target NVARCHAR(255) NOT NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_repair_audit_logs_created_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_repair_audit_logs PRIMARY KEY (id),
        CONSTRAINT FK_repair_audit_logs_actor FOREIGN KEY (actor_user_id) REFERENCES dbo.users(id) ON DELETE SET NULL ON UPDATE NO ACTION
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_audit_logs_created_at' AND object_id = OBJECT_ID(N'dbo.audit_logs'))
    CREATE INDEX IX_audit_logs_created_at ON dbo.audit_logs(created_at);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_audit_logs_actor' AND object_id = OBJECT_ID(N'dbo.audit_logs'))
    CREATE INDEX IX_audit_logs_actor ON dbo.audit_logs(actor_user_id);
GO

/* ------------------------------------------------------------
   INTEGRATION LOGS
   ------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.integration_logs', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.integration_logs
    (
        id BIGINT IDENTITY(1,1) NOT NULL,
        endpoint NVARCHAR(255) NOT NULL,
        status_code SMALLINT NOT NULL,
        payload NVARCHAR(MAX) NOT NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_repair_integration_logs_created_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_repair_integration_logs PRIMARY KEY (id),
        CONSTRAINT CK_repair_integration_logs_status CHECK (status_code BETWEEN 100 AND 599)
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_integration_logs_created_at' AND object_id = OBJECT_ID(N'dbo.integration_logs'))
    CREATE INDEX IX_integration_logs_created_at ON dbo.integration_logs(created_at);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_integration_logs_status' AND object_id = OBJECT_ID(N'dbo.integration_logs'))
    CREATE INDEX IX_integration_logs_status ON dbo.integration_logs(status_code);
GO

PRINT 'Artic existing-database repair completed successfully.';
GO

USE artic;
GO

/* ============================================================
   V16 FEATURE REPAIR: LISTING COVERS, PORTFOLIO, DEMO FINANCE
   ============================================================ */
IF COL_LENGTH(N'dbo.listings', N'cover_image_url') IS NULL
BEGIN
    ALTER TABLE dbo.listings ADD cover_image_url NVARCHAR(2048) NULL;
END
GO
IF COL_LENGTH(N'dbo.listings', N'cover_image_name') IS NULL
BEGIN
    ALTER TABLE dbo.listings ADD cover_image_name NVARCHAR(255) NULL;
END
GO
IF COL_LENGTH(N'dbo.listings', N'cover_image_mime') IS NULL
BEGIN
    ALTER TABLE dbo.listings ADD cover_image_mime NVARCHAR(120) NULL;
END
GO
IF COL_LENGTH(N'dbo.listings', N'cover_image_size') IS NULL
BEGIN
    ALTER TABLE dbo.listings ADD cover_image_size BIGINT NULL;
END
GO

/* ============================================================
   ARTIST PORTFOLIO
   ============================================================ */
IF OBJECT_ID(N'dbo.artist_portfolio', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.artist_portfolio
    (
        id INT IDENTITY(1,1) NOT NULL,
        artist_id INT NOT NULL,
        title NVARCHAR(160) NOT NULL,
        description NVARCHAR(1000) NULL,
        url NVARCHAR(2048) NOT NULL,
        file_name NVARCHAR(255) NULL,
        mime_type NVARCHAR(120) NULL,
        file_size BIGINT NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_artist_portfolio_created_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_artist_portfolio PRIMARY KEY (id),
        CONSTRAINT FK_artist_portfolio_artist FOREIGN KEY (artist_id) REFERENCES dbo.users(id) ON DELETE CASCADE ON UPDATE NO ACTION
    );
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_artist_portfolio_artist' AND object_id = OBJECT_ID(N'dbo.artist_portfolio'))
    CREATE INDEX IX_artist_portfolio_artist ON dbo.artist_portfolio(artist_id, created_at DESC, id DESC);
GO

/* ============================================================
   DEMO INVOICES & PAYOUTS
   These records are intentionally simulation-only. No real payment
   processor or money movement is performed by Artic.
   ============================================================ */
IF OBJECT_ID(N'dbo.invoices', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.invoices
    (
        id BIGINT IDENTITY(1,1) NOT NULL,
        commission_id INT NOT NULL,
        invoice_number NVARCHAR(40) NOT NULL,
        client_id INT NOT NULL,
        artist_id INT NOT NULL,
        subtotal DECIMAL(12,2) NOT NULL,
        platform_fee DECIMAL(12,2) NOT NULL CONSTRAINT DF_invoices_platform_fee DEFAULT 0,
        total DECIMAL(12,2) NOT NULL,
        status NVARCHAR(20) NOT NULL CONSTRAINT DF_invoices_status DEFAULT N'paid',
        issued_at DATETIME2(0) NOT NULL CONSTRAINT DF_invoices_issued_at DEFAULT SYSUTCDATETIME(),
        paid_at DATETIME2(0) NULL,
        CONSTRAINT PK_invoices PRIMARY KEY (id),
        CONSTRAINT UQ_invoices_commission UNIQUE (commission_id),
        CONSTRAINT UQ_invoices_number UNIQUE (invoice_number),
        CONSTRAINT FK_invoices_commission FOREIGN KEY (commission_id) REFERENCES dbo.commissions(id) ON DELETE CASCADE ON UPDATE NO ACTION,
        CONSTRAINT FK_invoices_client FOREIGN KEY (client_id) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT FK_invoices_artist FOREIGN KEY (artist_id) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT CK_invoices_status CHECK (status IN (N'issued', N'paid', N'void'))
    );
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_invoices_client' AND object_id = OBJECT_ID(N'dbo.invoices'))
    CREATE INDEX IX_invoices_client ON dbo.invoices(client_id, issued_at DESC);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_invoices_artist' AND object_id = OBJECT_ID(N'dbo.invoices'))
    CREATE INDEX IX_invoices_artist ON dbo.invoices(artist_id, issued_at DESC);
GO

IF OBJECT_ID(N'dbo.payouts', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.payouts
    (
        id BIGINT IDENTITY(1,1) NOT NULL,
        commission_id INT NOT NULL,
        invoice_id BIGINT NOT NULL,
        artist_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        status NVARCHAR(20) NOT NULL CONSTRAINT DF_payouts_status DEFAULT N'pending',
        payout_reference NVARCHAR(60) NOT NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_payouts_created_at DEFAULT SYSUTCDATETIME(),
        paid_at DATETIME2(0) NULL,
        CONSTRAINT PK_payouts PRIMARY KEY (id),
        CONSTRAINT UQ_payouts_commission UNIQUE (commission_id),
        CONSTRAINT UQ_payouts_reference UNIQUE (payout_reference),
        CONSTRAINT FK_payouts_commission FOREIGN KEY (commission_id) REFERENCES dbo.commissions(id) ON DELETE CASCADE ON UPDATE NO ACTION,
        CONSTRAINT FK_payouts_invoice FOREIGN KEY (invoice_id) REFERENCES dbo.invoices(id) ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT FK_payouts_artist FOREIGN KEY (artist_id) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT CK_payouts_status CHECK (status IN (N'pending', N'paid', N'cancelled'))
    );
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_payouts_artist' AND object_id = OBJECT_ID(N'dbo.payouts'))
    CREATE INDEX IX_payouts_artist ON dbo.payouts(artist_id, created_at DESC);
GO

/* ============================================================
   PAYPAL CHECKOUT TRANSACTIONS
   Sandbox/live PayPal order + capture references. Secrets are never stored here.
   ============================================================ */
IF OBJECT_ID(N'dbo.paypal_transactions', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.paypal_transactions
    (
        id BIGINT IDENTITY(1,1) NOT NULL,
        commission_id INT NOT NULL,
        client_id INT NOT NULL,
        artist_id INT NOT NULL,
        paypal_order_id NVARCHAR(64) NOT NULL,
        paypal_capture_id NVARCHAR(64) NULL,
        environment NVARCHAR(20) NOT NULL CONSTRAINT DF_paypal_transactions_environment DEFAULT N'sandbox',
        currency CHAR(3) NOT NULL CONSTRAINT DF_paypal_transactions_currency DEFAULT 'USD',
        amount DECIMAL(12,2) NOT NULL,
        status NVARCHAR(30) NOT NULL,
        payer_email NVARCHAR(320) NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_paypal_transactions_created_at DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_paypal_transactions_updated_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_paypal_transactions PRIMARY KEY (id),
        CONSTRAINT UQ_paypal_transactions_commission UNIQUE (commission_id),
        CONSTRAINT UQ_paypal_transactions_order UNIQUE (paypal_order_id),
        CONSTRAINT FK_paypal_transactions_commission FOREIGN KEY (commission_id) REFERENCES dbo.commissions(id) ON DELETE CASCADE ON UPDATE NO ACTION,
        CONSTRAINT FK_paypal_transactions_client FOREIGN KEY (client_id) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT FK_paypal_transactions_artist FOREIGN KEY (artist_id) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION
    );
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_paypal_transactions_client' AND object_id = OBJECT_ID(N'dbo.paypal_transactions'))
    CREATE INDEX IX_paypal_transactions_client ON dbo.paypal_transactions(client_id, created_at DESC);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_paypal_transactions_artist' AND object_id = OBJECT_ID(N'dbo.paypal_transactions'))
    CREATE INDEX IX_paypal_transactions_artist ON dbo.paypal_transactions(artist_id, created_at DESC);
GO

