USE artic;
GO

/* Artic messaging migration. Safe to run more than once. */


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
