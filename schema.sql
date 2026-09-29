/* ============================================================
   ARTIC
   E-Commerce Commission & Creative Asset Monitoring Portal
   Microsoft SQL Server / T-SQL
   ============================================================ */

USE master;
GO

IF DB_ID(N'artic') IS NULL
BEGIN
    CREATE DATABASE artic;
END
GO

USE artic;
GO

/* ============================================================
   USERS
   ============================================================ */

IF OBJECT_ID(N'dbo.users', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.users
    (
        id INT IDENTITY(1,1) NOT NULL,
        name NVARCHAR(100) NOT NULL,
        email NVARCHAR(190) NOT NULL,
        password_hash NVARCHAR(255) NOT NULL,
        role NVARCHAR(20) NOT NULL,
        bio NVARCHAR(MAX) NULL,
        is_verified BIT NOT NULL CONSTRAINT DF_users_is_verified DEFAULT 0,
        verified_by_code NVARCHAR(50) NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_users_created_at DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_users_updated_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_users PRIMARY KEY (id),
        CONSTRAINT UQ_users_email UNIQUE (email),
        CONSTRAINT CK_users_role CHECK (role IN (N'client', N'artist', N'admin'))
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_users_role_verified' AND object_id = OBJECT_ID(N'dbo.users'))
    CREATE INDEX IX_users_role_verified ON dbo.users(role, is_verified);
GO

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

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_users_created_at' AND object_id = OBJECT_ID(N'dbo.users'))
    CREATE INDEX IX_users_created_at ON dbo.users(created_at);
GO

/* ============================================================
   INVITE CODES
   ============================================================ */

IF OBJECT_ID(N'dbo.invite_codes', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.invite_codes
    (
        id INT IDENTITY(1,1) NOT NULL,
        code NVARCHAR(30) NOT NULL,
        created_by INT NULL,
        is_used BIT NOT NULL CONSTRAINT DF_invite_codes_is_used DEFAULT 0,
        used_by INT NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_invite_codes_created_at DEFAULT SYSUTCDATETIME(),
        used_at DATETIME2(0) NULL,
        CONSTRAINT PK_invite_codes PRIMARY KEY (id),
        CONSTRAINT UQ_invite_codes_code UNIQUE (code),
        CONSTRAINT FK_invite_codes_created_by
            FOREIGN KEY (created_by) REFERENCES dbo.users(id)
            ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT FK_invite_codes_used_by
            FOREIGN KEY (used_by) REFERENCES dbo.users(id)
            ON DELETE NO ACTION ON UPDATE NO ACTION
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_invite_codes_creator_status' AND object_id = OBJECT_ID(N'dbo.invite_codes'))
    CREATE INDEX IX_invite_codes_creator_status ON dbo.invite_codes(created_by, is_used);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_invite_codes_used_by' AND object_id = OBJECT_ID(N'dbo.invite_codes'))
    CREATE INDEX IX_invite_codes_used_by ON dbo.invite_codes(used_by);
GO

/* ============================================================
   LISTINGS
   ============================================================ */

IF OBJECT_ID(N'dbo.listings', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.listings
    (
        id INT IDENTITY(1,1) NOT NULL,
        artist_id INT NOT NULL,
        title NVARCHAR(160) NOT NULL,
        category NVARCHAR(40) NOT NULL,
        price DECIMAL(12,2) NOT NULL,
        delivery_days SMALLINT NOT NULL,
        slots_total SMALLINT NOT NULL,
        slots_used SMALLINT NOT NULL CONSTRAINT DF_listings_slots_used DEFAULT 0,
        description NVARCHAR(MAX) NOT NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_listings_created_at DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_listings_updated_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_listings PRIMARY KEY (id),
        CONSTRAINT FK_listings_artist FOREIGN KEY (artist_id) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT CK_listings_price CHECK (price >= 0),
        CONSTRAINT CK_listings_delivery_days CHECK (delivery_days > 0),
        CONSTRAINT CK_listings_slots_total CHECK (slots_total > 0),
        CONSTRAINT CK_listings_slots_used CHECK (slots_used >= 0 AND slots_used <= slots_total)
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_listings_artist' AND object_id = OBJECT_ID(N'dbo.listings'))
    CREATE INDEX IX_listings_artist ON dbo.listings(artist_id);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_listings_category' AND object_id = OBJECT_ID(N'dbo.listings'))
    CREATE INDEX IX_listings_category ON dbo.listings(category);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_listings_created_at' AND object_id = OBJECT_ID(N'dbo.listings'))
    CREATE INDEX IX_listings_created_at ON dbo.listings(created_at);
GO

/* ============================================================
   COMMISSIONS
   ============================================================ */

IF OBJECT_ID(N'dbo.commissions', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.commissions
    (
        id INT IDENTITY(1,1) NOT NULL,
        listing_id INT NOT NULL,
        client_id INT NOT NULL,
        artist_id INT NOT NULL,
        status NVARCHAR(20) NOT NULL CONSTRAINT DF_commissions_status DEFAULT N'requested',
        payment_status NVARCHAR(20) NOT NULL CONSTRAINT DF_commissions_payment_status DEFAULT N'pending',
        price DECIMAL(12,2) NOT NULL,
        brief NVARCHAR(MAX) NOT NULL,
        revisions INT NOT NULL CONSTRAINT DF_commissions_revisions DEFAULT 0,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_commissions_created_at DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_commissions_updated_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_commissions PRIMARY KEY (id),
        CONSTRAINT FK_commissions_listing FOREIGN KEY (listing_id) REFERENCES dbo.listings(id) ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT FK_commissions_client FOREIGN KEY (client_id) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT FK_commissions_artist FOREIGN KEY (artist_id) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT CK_commissions_status CHECK (status IN (N'requested', N'accepted', N'declined', N'cancelled', N'inprogress', N'inreview', N'delivered')),
        CONSTRAINT CK_commissions_payment_status CHECK (payment_status IN (N'pending', N'paid', N'released', N'refunded')),
        CONSTRAINT CK_commissions_price CHECK (price >= 0),
        CONSTRAINT CK_commissions_revisions CHECK (revisions >= 0)
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_commissions_client' AND object_id = OBJECT_ID(N'dbo.commissions'))
    CREATE INDEX IX_commissions_client ON dbo.commissions(client_id, created_at);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_commissions_artist' AND object_id = OBJECT_ID(N'dbo.commissions'))
    CREATE INDEX IX_commissions_artist ON dbo.commissions(artist_id, created_at);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_commissions_listing' AND object_id = OBJECT_ID(N'dbo.commissions'))
    CREATE INDEX IX_commissions_listing ON dbo.commissions(listing_id);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_commissions_status' AND object_id = OBJECT_ID(N'dbo.commissions'))
    CREATE INDEX IX_commissions_status ON dbo.commissions(status);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_commissions_payment_status' AND object_id = OBJECT_ID(N'dbo.commissions'))
    CREATE INDEX IX_commissions_payment_status ON dbo.commissions(payment_status);
GO

/* ============================================================
   COMMISSION DIRECT MESSAGES
   ============================================================ */

IF OBJECT_ID(N'dbo.commission_messages', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.commission_messages
    (
        id BIGINT IDENTITY(1,1) NOT NULL,
        commission_id INT NOT NULL,
        sender_id INT NOT NULL,
        recipient_id INT NOT NULL,
        [message] NVARCHAR(MAX) NOT NULL,
        sent_at DATETIME2(0) NOT NULL CONSTRAINT DF_commission_messages_sent_at DEFAULT SYSUTCDATETIME(),
        read_at DATETIME2(0) NULL,
        CONSTRAINT PK_commission_messages PRIMARY KEY (id),
        CONSTRAINT FK_commission_messages_commission FOREIGN KEY (commission_id) REFERENCES dbo.commissions(id) ON DELETE CASCADE ON UPDATE NO ACTION,
        CONSTRAINT FK_commission_messages_sender FOREIGN KEY (sender_id) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT FK_commission_messages_recipient FOREIGN KEY (recipient_id) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT CK_commission_messages_participants CHECK (sender_id <> recipient_id)
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

/* ============================================================
   COMMISSION STATUS HISTORY
   ============================================================ */

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
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_commission_status_history_created_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_commission_status_history PRIMARY KEY (id),
        CONSTRAINT FK_status_history_commission FOREIGN KEY (commission_id) REFERENCES dbo.commissions(id) ON DELETE CASCADE ON UPDATE NO ACTION,
        CONSTRAINT FK_status_history_actor FOREIGN KEY (actor_id) REFERENCES dbo.users(id) ON DELETE SET NULL ON UPDATE NO ACTION,
        CONSTRAINT CK_status_history_from_status CHECK (from_status IS NULL OR from_status IN (N'requested', N'accepted', N'declined', N'cancelled', N'inprogress', N'inreview', N'delivered')),
        CONSTRAINT CK_status_history_to_status CHECK (to_status IN (N'requested', N'accepted', N'declined', N'cancelled', N'inprogress', N'inreview', N'delivered'))
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_status_history_commission_created' AND object_id = OBJECT_ID(N'dbo.commission_status_history'))
    CREATE INDEX IX_status_history_commission_created ON dbo.commission_status_history(commission_id, created_at);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_status_history_actor' AND object_id = OBJECT_ID(N'dbo.commission_status_history'))
    CREATE INDEX IX_status_history_actor ON dbo.commission_status_history(actor_id);
GO

/* ============================================================
   COMMISSION FILES
   ============================================================ */

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
        version_no INT NOT NULL CONSTRAINT DF_commission_files_version_no DEFAULT 1,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_commission_files_created_at DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_commission_files_updated_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_commission_files PRIMARY KEY (id),
        CONSTRAINT FK_commission_files_commission FOREIGN KEY (commission_id) REFERENCES dbo.commissions(id) ON DELETE CASCADE ON UPDATE NO ACTION,
        CONSTRAINT FK_commission_files_user FOREIGN KEY (added_by) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT CK_commission_files_type CHECK (type IN (N'wip', N'final'))
    );
END
GO

IF COL_LENGTH(N'dbo.commission_files', N'version_no') IS NULL
BEGIN
    ALTER TABLE dbo.commission_files
        ADD version_no INT NOT NULL CONSTRAINT DF_commission_files_version_no DEFAULT 1 WITH VALUES;
END
GO

IF COL_LENGTH(N'dbo.commission_files', N'file_name') IS NULL
BEGIN
    ALTER TABLE dbo.commission_files ADD file_name NVARCHAR(255) NULL;
END
GO

IF COL_LENGTH(N'dbo.commission_files', N'mime_type') IS NULL
BEGIN
    ALTER TABLE dbo.commission_files ADD mime_type NVARCHAR(120) NULL;
END
GO

IF COL_LENGTH(N'dbo.commission_files', N'file_size') IS NULL
BEGIN
    ALTER TABLE dbo.commission_files ADD file_size BIGINT NULL;
END
GO

;WITH RankedFiles AS
(
    SELECT id, ROW_NUMBER() OVER (PARTITION BY commission_id ORDER BY created_at ASC, id ASC) AS rn
    FROM dbo.commission_files
)
UPDATE f
SET version_no = r.rn
FROM dbo.commission_files AS f
INNER JOIN RankedFiles AS r ON r.id = f.id;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_commission_files_version' AND object_id = OBJECT_ID(N'dbo.commission_files'))
    CREATE UNIQUE INDEX UX_commission_files_version ON dbo.commission_files(commission_id, version_no);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_commission_files_commission_created' AND object_id = OBJECT_ID(N'dbo.commission_files'))
    CREATE INDEX IX_commission_files_commission_created ON dbo.commission_files(commission_id, created_at);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_commission_files_added_by' AND object_id = OBJECT_ID(N'dbo.commission_files'))
    CREATE INDEX IX_commission_files_added_by ON dbo.commission_files(added_by);
GO

/* ============================================================
   COMMISSION COMMENTS
   ============================================================ */

IF OBJECT_ID(N'dbo.commission_comments', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.commission_comments
    (
        id INT IDENTITY(1,1) NOT NULL,
        commission_id INT NOT NULL,
        author_id INT NOT NULL,
        [text] NVARCHAR(MAX) NOT NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_commission_comments_created_at DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_commission_comments_updated_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_commission_comments PRIMARY KEY (id),
        CONSTRAINT FK_commission_comments_commission FOREIGN KEY (commission_id) REFERENCES dbo.commissions(id) ON DELETE CASCADE ON UPDATE NO ACTION,
        CONSTRAINT FK_commission_comments_author FOREIGN KEY (author_id) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_commission_comments_commission_created' AND object_id = OBJECT_ID(N'dbo.commission_comments'))
    CREATE INDEX IX_commission_comments_commission_created ON dbo.commission_comments(commission_id, created_at);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_commission_comments_author' AND object_id = OBJECT_ID(N'dbo.commission_comments'))
    CREATE INDEX IX_commission_comments_author ON dbo.commission_comments(author_id);
GO

/* ============================================================
   DIRECT COMMISSION MESSAGES
   ============================================================ */

IF OBJECT_ID(N'dbo.commission_messages', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.commission_messages
    (
        id BIGINT IDENTITY(1,1) NOT NULL,
        commission_id INT NOT NULL,
        sender_id INT NOT NULL,
        recipient_id INT NOT NULL,
        [message] NVARCHAR(MAX) NOT NULL,
        sent_at DATETIME2(0) NOT NULL CONSTRAINT DF_commission_messages_sent_at DEFAULT SYSUTCDATETIME(),
        read_at DATETIME2(0) NULL,
        CONSTRAINT PK_commission_messages PRIMARY KEY (id),
        CONSTRAINT FK_commission_messages_commission FOREIGN KEY (commission_id) REFERENCES dbo.commissions(id) ON DELETE CASCADE ON UPDATE NO ACTION,
        CONSTRAINT FK_commission_messages_sender FOREIGN KEY (sender_id) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT FK_commission_messages_recipient FOREIGN KEY (recipient_id) REFERENCES dbo.users(id) ON DELETE NO ACTION ON UPDATE NO ACTION,
        CONSTRAINT CK_commission_messages_participants CHECK (sender_id <> recipient_id)
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

/* ============================================================
   NOTIFICATIONS
   ============================================================ */

IF OBJECT_ID(N'dbo.notifications', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.notifications
    (
        id INT IDENTITY(1,1) NOT NULL,
        user_id INT NOT NULL,
        commission_id INT NULL,
        message NVARCHAR(500) NOT NULL,
        is_read BIT NOT NULL CONSTRAINT DF_notifications_is_read DEFAULT 0,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_notifications_created_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_notifications PRIMARY KEY (id),
        CONSTRAINT FK_notifications_user FOREIGN KEY (user_id) REFERENCES dbo.users(id) ON DELETE CASCADE ON UPDATE NO ACTION,
        CONSTRAINT FK_notifications_commission FOREIGN KEY (commission_id) REFERENCES dbo.commissions(id) ON DELETE SET NULL ON UPDATE NO ACTION
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_notifications_user_read_created' AND object_id = OBJECT_ID(N'dbo.notifications'))
    CREATE INDEX IX_notifications_user_read_created ON dbo.notifications(user_id, is_read, created_at);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_notifications_commission' AND object_id = OBJECT_ID(N'dbo.notifications'))
    CREATE INDEX IX_notifications_commission ON dbo.notifications(commission_id);
GO

/* ============================================================
   AUDIT LOGS
   ============================================================ */

IF OBJECT_ID(N'dbo.audit_logs', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.audit_logs
    (
        id BIGINT IDENTITY(1,1) NOT NULL,
        actor_user_id INT NULL,
        actor_name NVARCHAR(100) NOT NULL,
        action NVARCHAR(160) NOT NULL,
        target NVARCHAR(255) NOT NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_audit_logs_created_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_audit_logs PRIMARY KEY (id),
        CONSTRAINT FK_audit_logs_actor FOREIGN KEY (actor_user_id) REFERENCES dbo.users(id) ON DELETE SET NULL ON UPDATE NO ACTION
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_audit_logs_created_at' AND object_id = OBJECT_ID(N'dbo.audit_logs'))
    CREATE INDEX IX_audit_logs_created_at ON dbo.audit_logs(created_at);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_audit_logs_actor' AND object_id = OBJECT_ID(N'dbo.audit_logs'))
    CREATE INDEX IX_audit_logs_actor ON dbo.audit_logs(actor_user_id);
GO

/* ============================================================
   INTEGRATION LOGS
   ============================================================ */

IF OBJECT_ID(N'dbo.integration_logs', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.integration_logs
    (
        id BIGINT IDENTITY(1,1) NOT NULL,
        endpoint NVARCHAR(255) NOT NULL,
        status_code SMALLINT NOT NULL,
        payload NVARCHAR(MAX) NOT NULL,
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_integration_logs_created_at DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_integration_logs PRIMARY KEY (id),
        CONSTRAINT CK_integration_logs_status_code CHECK (status_code BETWEEN 100 AND 599)
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_integration_logs_created_at' AND object_id = OBJECT_ID(N'dbo.integration_logs'))
    CREATE INDEX IX_integration_logs_created_at ON dbo.integration_logs(created_at);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_integration_logs_status' AND object_id = OBJECT_ID(N'dbo.integration_logs'))
    CREATE INDEX IX_integration_logs_status ON dbo.integration_logs(status_code);
GO

/* ============================================================
   SEED USERS
   ============================================================ */

SET IDENTITY_INSERT dbo.users ON;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.users WHERE id = 1)
INSERT INTO dbo.users (id,name,email,password_hash,role,bio,is_verified,verified_by_code,created_at,updated_at)
VALUES (1,N'Artic Admin',N'admin@artic.io',N'$2y$12$JwEpcYgmvCCHMjLAdV9b/OJkHfiulDT7XKLHj5QonsOHKui4tsbui',N'admin',NULL,1,N'SYSTEM',DATEADD(DAY,-300,SYSUTCDATETIME()),SYSUTCDATETIME());
GO

IF NOT EXISTS (SELECT 1 FROM dbo.users WHERE id = 2)
INSERT INTO dbo.users (id,name,email,password_hash,role,bio,is_verified,verified_by_code,created_at,updated_at)
VALUES (2,N'Sora Kaze',N'sora@artic.io',N'$2y$12$laaJWRC30sx37npgXonTl.X4EsWs.rS4S/tkkd4jLIz58cS6h6khu',N'artist',N'Anime character designer and Live2D modeler. 8+ years experience in VTuber assets.',1,N'FOUNDER-VERIFIED',DATEADD(DAY,-180,SYSUTCDATETIME()),SYSUTCDATETIME());
GO

IF NOT EXISTS (SELECT 1 FROM dbo.users WHERE id = 3)
INSERT INTO dbo.users (id,name,email,password_hash,role,bio,is_verified,verified_by_code,created_at,updated_at)
VALUES (3,N'Marcus Vance',N'marcus@artic.io',N'$2y$12$laaJWRC30sx37npgXonTl.X4EsWs.rS4S/tkkd4jLIz58cS6h6khu',N'artist',N'3D game environment artist and hard-surface prop designer.',1,N'ARTIC-SEED-0001',DATEADD(DAY,-90,SYSUTCDATETIME()),SYSUTCDATETIME());
GO

IF NOT EXISTS (SELECT 1 FROM dbo.users WHERE id = 4)
INSERT INTO dbo.users (id,name,email,password_hash,role,bio,is_verified,verified_by_code,created_at,updated_at)
VALUES (4,N'Nova Wright',N'nova@artic.io',N'$2y$12$laaJWRC30sx37npgXonTl.X4EsWs.rS4S/tkkd4jLIz58cS6h6khu',N'artist',N'Digital painter specializing in fantasy concept art.',0,NULL,DATEADD(DAY,-2,SYSUTCDATETIME()),SYSUTCDATETIME());
GO

IF NOT EXISTS (SELECT 1 FROM dbo.users WHERE id = 5)
INSERT INTO dbo.users (id,name,email,password_hash,role,bio,is_verified,verified_by_code,created_at,updated_at)
VALUES (5,N'Elena Rostova',N'elena@gmail.com',N'$2y$12$Tzey6voFmdKnmXvxU7hFzudlvi9ek.9nsEg4Yr2BV5WA12qwJtuJS',N'client',NULL,0,NULL,DATEADD(DAY,-60,SYSUTCDATETIME()),SYSUTCDATETIME());
GO

IF NOT EXISTS (SELECT 1 FROM dbo.users WHERE id = 6)
INSERT INTO dbo.users (id,name,email,password_hash,role,bio,is_verified,verified_by_code,created_at,updated_at)
VALUES (6,N'GamerStudio HQ',N'dev@gamerstudio.com',N'$2y$12$Tzey6voFmdKnmXvxU7hFzudlvi9ek.9nsEg4Yr2BV5WA12qwJtuJS',N'client',NULL,0,NULL,DATEADD(DAY,-30,SYSUTCDATETIME()),SYSUTCDATETIME());
GO

SET IDENTITY_INSERT dbo.users OFF;
GO

/* ============================================================
   SEED INVITE CODES
   ============================================================ */

SET IDENTITY_INSERT dbo.invite_codes ON;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.invite_codes WHERE id = 1)
INSERT INTO dbo.invite_codes (id,code,created_by,is_used,used_by,created_at,used_at)
VALUES (1,N'ARTIC-SEED-0001',2,1,3,DATEADD(DAY,-10,SYSUTCDATETIME()),DATEADD(DAY,-8,SYSUTCDATETIME()));
GO

IF NOT EXISTS (SELECT 1 FROM dbo.invite_codes WHERE id = 2)
INSERT INTO dbo.invite_codes (id,code,created_by,is_used,used_by,created_at,used_at)
VALUES (2,N'ARTIC-SEED-0002',2,0,NULL,DATEADD(DAY,-10,SYSUTCDATETIME()),NULL);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.invite_codes WHERE id = 3)
INSERT INTO dbo.invite_codes (id,code,created_by,is_used,used_by,created_at,used_at)
VALUES (3,N'ARTIC-SEED-0003',3,0,NULL,DATEADD(DAY,-5,SYSUTCDATETIME()),NULL);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.invite_codes WHERE id = 4)
INSERT INTO dbo.invite_codes (id,code,created_by,is_used,used_by,created_at,used_at)
VALUES (4,N'ARTIC-SEED-0004',3,0,NULL,DATEADD(DAY,-5,SYSUTCDATETIME()),NULL);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.invite_codes WHERE id = 5)
INSERT INTO dbo.invite_codes (id,code,created_by,is_used,used_by,created_at,used_at)
VALUES (5,N'ARTIC-SEED-0005',1,0,NULL,DATEADD(DAY,-1,SYSUTCDATETIME()),NULL);
GO

SET IDENTITY_INSERT dbo.invite_codes OFF;
GO

/* ============================================================
   SEED LISTINGS
   ============================================================ */

SET IDENTITY_INSERT dbo.listings ON;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.listings WHERE id = 1)
INSERT INTO dbo.listings (id,artist_id,title,category,price,delivery_days,slots_total,slots_used,description,created_at,updated_at)
VALUES (1,2,N'VTuber Model Design & Rigging',N'Live2D',650.00,21,3,1,N'Full-body Live2D VTuber model with detailed physics, 3 expression toggles, and commercial license.',DATEADD(DAY,-180,SYSUTCDATETIME()),SYSUTCDATETIME());
GO

IF NOT EXISTS (SELECT 1 FROM dbo.listings WHERE id = 2)
INSERT INTO dbo.listings (id,artist_id,title,category,price,delivery_days,slots_total,slots_used,description,created_at,updated_at)
VALUES (2,2,N'Anime Style Bust Portrait',N'Illustration',95.00,7,5,1,N'High-res bust portrait of your OC or favorite character with soft cel shading.',DATEADD(DAY,-150,SYSUTCDATETIME()),SYSUTCDATETIME());
GO

IF NOT EXISTS (SELECT 1 FROM dbo.listings WHERE id = 3)
INSERT INTO dbo.listings (id,artist_id,title,category,price,delivery_days,slots_total,slots_used,description,created_at,updated_at)
VALUES (3,3,N'Stylized 3D Game Prop',N'3D Asset',180.00,10,4,0,N'PBR textured 3D prop optimized for Unity/Unreal Engine, FBX + Blender source file.',DATEADD(DAY,-90,SYSUTCDATETIME()),SYSUTCDATETIME());
GO

SET IDENTITY_INSERT dbo.listings OFF;
GO

/* ============================================================
   SEED COMMISSION
   ============================================================ */

SET IDENTITY_INSERT dbo.commissions ON;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.commissions WHERE id = 101)
INSERT INTO dbo.commissions (id,listing_id,client_id,artist_id,status,payment_status,price,brief,revisions,created_at,updated_at)
VALUES (101,1,5,2,N'inreview',N'paid',650.00,N'Need a cyber-gothic VTuber avatar with silver hair and neon purple eyes.',0,DATEADD(DAY,-12,SYSUTCDATETIME()),DATEADD(DAY,-1,SYSUTCDATETIME()));
GO

SET IDENTITY_INSERT dbo.commissions OFF;
GO

/* ============================================================
   SEED STATUS HISTORY
   ============================================================ */

IF NOT EXISTS (SELECT 1 FROM dbo.commission_status_history WHERE commission_id = 101)
BEGIN
    INSERT INTO dbo.commission_status_history (commission_id,from_status,to_status,actor_id,note,created_at)
    VALUES
    (101,NULL,N'requested',5,N'Client submitted a commission request.',DATEADD(DAY,-12,SYSUTCDATETIME())),
    (101,N'requested',N'accepted',2,N'Artist accepted the commission.',DATEADD(DAY,-12,SYSUTCDATETIME())),
    (101,N'accepted',N'inprogress',2,N'Artist started production.',DATEADD(DAY,-11,SYSUTCDATETIME())),
    (101,N'inprogress',N'inreview',2,N'Artist submitted the draft for client review.',DATEADD(DAY,-1,SYSUTCDATETIME()));
END
GO

/* ============================================================
   SEED FILES
   ============================================================ */

SET IDENTITY_INSERT dbo.commission_files ON;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.commission_files WHERE id = 1001)
INSERT INTO dbo.commission_files (id,commission_id,type,label,url,added_by,version_no,created_at,updated_at)
VALUES (1001,101,N'wip',N'Concept Sketch v1',N'https://github.com/PlaqueGod/artic',2,1,DATEADD(DAY,-8,SYSUTCDATETIME()),SYSUTCDATETIME());
GO

IF NOT EXISTS (SELECT 1 FROM dbo.commission_files WHERE id = 1002)
INSERT INTO dbo.commission_files (id,commission_id,type,label,url,added_by,version_no,created_at,updated_at)
VALUES (1002,101,N'wip',N'Separated PSD Layers',N'https://github.com/PlaqueGod/artic',2,2,DATEADD(DAY,-3,SYSUTCDATETIME()),SYSUTCDATETIME());
GO

SET IDENTITY_INSERT dbo.commission_files OFF;
GO

/* ============================================================
   SEED COMMENTS
   ============================================================ */

SET IDENTITY_INSERT dbo.commission_comments ON;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.commission_comments WHERE id = 2001)
INSERT INTO dbo.commission_comments (id,commission_id,author_id,[text],created_at,updated_at)
VALUES (2001,101,2,N'First PSD separation completed. Ready for review before rigging starts.',DATEADD(DAY,-3,SYSUTCDATETIME()),SYSUTCDATETIME());
GO

IF NOT EXISTS (SELECT 1 FROM dbo.commission_comments WHERE id = 2002)
INSERT INTO dbo.commission_comments (id,commission_id,author_id,[text],created_at,updated_at)
VALUES (2002,101,5,N'Looks incredible! Layers look clean.',DATEADD(DAY,-2,SYSUTCDATETIME()),SYSUTCDATETIME());
GO

SET IDENTITY_INSERT dbo.commission_comments OFF;
GO

/* ============================================================
   SEED NOTIFICATION
   ============================================================ */

SET IDENTITY_INSERT dbo.notifications ON;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.notifications WHERE id = 3001)
INSERT INTO dbo.notifications (id,user_id,commission_id,message,is_read,created_at)
VALUES (3001,5,101,N'Sora Kaze uploaded a new WIP file for VTuber Model Design.',0,DATEADD(DAY,-1,SYSUTCDATETIME()));
GO

SET IDENTITY_INSERT dbo.notifications OFF;
GO

/* ============================================================
   SEED AUDIT LOGS
   ============================================================ */

SET IDENTITY_INSERT dbo.audit_logs ON;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.audit_logs WHERE id = 4001)
INSERT INTO dbo.audit_logs (id,actor_user_id,actor_name,action,target,created_at)
VALUES (4001,5,N'Elena Rostova',N'Commission Requested',N'c-101 (VTuber Model)',DATEADD(DAY,-12,SYSUTCDATETIME()));
GO

IF NOT EXISTS (SELECT 1 FROM dbo.audit_logs WHERE id = 4002)
INSERT INTO dbo.audit_logs (id,actor_user_id,actor_name,action,target,created_at)
VALUES (4002,2,N'Sora Kaze',N'Commission Accepted',N'c-101',DATEADD(DAY,-12,SYSUTCDATETIME()));
GO

IF NOT EXISTS (SELECT 1 FROM dbo.audit_logs WHERE id = 4003)
INSERT INTO dbo.audit_logs (id,actor_user_id,actor_name,action,target,created_at)
VALUES (4003,5,N'Elena Rostova',N'Escrow Payment Locked',N'$650 (c-101)',DATEADD(DAY,-11,SYSUTCDATETIME()));
GO

SET IDENTITY_INSERT dbo.audit_logs OFF;
GO

/* ============================================================
   SEED INTEGRATION LOGS
   ============================================================ */

SET IDENTITY_INSERT dbo.integration_logs ON;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.integration_logs WHERE id = 5001)
INSERT INTO dbo.integration_logs (id,endpoint,status_code,payload,created_at)
VALUES (5001,N'POST /api/commissions/create',201,N'{"listingId":1,"clientId":5,"price":650}',DATEADD(DAY,-12,SYSUTCDATETIME()));
GO

IF NOT EXISTS (SELECT 1 FROM dbo.integration_logs WHERE id = 5002)
INSERT INTO dbo.integration_logs (id,endpoint,status_code,payload,created_at)
VALUES (5002,N'POST /api/payments/escrow-lock',200,N'{"commissionId":101,"amount":650}',DATEADD(DAY,-11,SYSUTCDATETIME()));
GO

SET IDENTITY_INSERT dbo.integration_logs OFF;
GO

/* ============================================================
   VERIFICATION
   ============================================================ */

SELECT TABLE_SCHEMA, TABLE_NAME
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = N'dbo'
ORDER BY TABLE_NAME;
GO

SELECT id, name, email, role, is_verified
FROM dbo.users
ORDER BY id;
GO

SELECT id, code, created_by, is_used, used_by
FROM dbo.invite_codes
ORDER BY id;
GO

SELECT id, artist_id, title, category, price, slots_total, slots_used
FROM dbo.listings
ORDER BY id;
GO

SELECT id, listing_id, client_id, artist_id, status, payment_status, price
FROM dbo.commissions
ORDER BY id;
GO


/* ============================================================
   LISTING COVER IMAGES
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

