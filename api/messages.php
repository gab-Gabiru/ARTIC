<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $pdo = db();
    if (!table_exists($pdo, 'commission_messages')) {
        json_response(false, 'Messaging is not installed in this Artic database. Run setup.php once to repair the database.', null, 503);
    }
    $action = $_GET['action'] ?? '';
    $user = require_auth($pdo);

    if ($action === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $fileSelect = 'm.id, m.commission_id, m.sender_id, m.recipient_id, m.[message], m.sent_at, m.read_at';
        foreach ([
            'attachment_url' => 'm.attachment_url',
            'attachment_name' => 'm.attachment_name',
            'attachment_mime' => 'm.attachment_mime',
            'attachment_size' => 'm.attachment_size',
        ] as $column => $select) {
            $fileSelect .= column_exists($pdo, 'commission_messages', $column)
                ? ', ' . $select
                : ', CAST(NULL AS NVARCHAR(2048)) AS ' . $column;
        }
        // Correct the types for the optional BIGINT field when the column is absent.
        if (!column_exists($pdo, 'commission_messages', 'attachment_size')) {
            $fileSelect = str_replace('CAST(NULL AS NVARCHAR(2048)) AS attachment_size', 'CAST(NULL AS BIGINT) AS attachment_size', $fileSelect);
        }

        $stmt = $pdo->prepare(
            "SELECT {$fileSelect}
             FROM dbo.commission_messages m
             INNER JOIN dbo.commissions c ON c.id = m.commission_id
             WHERE m.sender_id = :sender_user_id OR m.recipient_id = :recipient_user_id
             ORDER BY m.sent_at ASC, m.id ASC"
        );
        $stmt->execute(['sender_user_id' => (int)$user['id'], 'recipient_user_id' => (int)$user['id']]);

        $items = [];
        foreach ($stmt as $row) $items[] = message_view($row);
        json_response(true, 'Messages loaded.', ['messages' => $items]);
    }

    if ($action === 'send') {
        request_method('POST');
        $input = !empty($_FILES) || !empty($_POST) ? $_POST : input_json();
        require_csrf($input);

        $commissionId = int_input($input, 'commissionId', 1);
        $messageText = str_input($input, 'message', 5000, false);
        $messageText = trim($messageText);

        $stmt = $pdo->prepare(
            'SELECT TOP (1) id, client_id, artist_id, status
             FROM dbo.commissions WHERE id = :id'
        );
        $stmt->execute(['id' => $commissionId]);
        $commission = $stmt->fetch();
        if (!$commission) json_response(false, 'Commission record not found.', null, 404);
        if (in_array((string)$commission['status'], ['declined', 'cancelled'], true)) {
            json_response(false, 'Messages are unavailable for a declined or cancelled commission.', null, 409);
        }

        $userId = (int)$user['id'];
        $clientId = (int)$commission['client_id'];
        $artistId = (int)$commission['artist_id'];
        if ($userId !== $clientId && $userId !== $artistId) {
            json_response(false, 'You are not a participant in this commission.', null, 403);
        }
        $recipientId = $userId === $clientId ? $artistId : $clientId;

        $asset = null;
        if (isset($_FILES['attachment']) && (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $asset = validate_uploaded_art_asset($_FILES['attachment']);
            $messageText = $messageText === '' ? 'Sent an attachment.' : $messageText;
        }

        if ($messageText === '' && $asset === null) {
            json_response(false, 'Write a message or attach an image/PSD file.', null, 400);
        }

        $attachmentUrl = null;
        $attachmentName = null;
        $attachmentMime = null;
        $attachmentSize = null;
        if ($asset) {
            $attachmentUrl = save_uploaded_art_asset($asset, $commissionId, 'message_attachments');
            $attachmentName = $asset['originalName'];
            $attachmentMime = $asset['mimeType'];
            $attachmentSize = $asset['size'];
        }

        $pdo->beginTransaction();
        $hasAttachmentCols = column_exists($pdo, 'commission_messages', 'attachment_url');
        if ($hasAttachmentCols) {
            $insert = $pdo->prepare(
                'INSERT INTO dbo.commission_messages
                 (commission_id, sender_id, recipient_id, [message], attachment_url, attachment_name, attachment_mime, attachment_size, sent_at, read_at)
                 OUTPUT INSERTED.id
                 VALUES (:commission_id, :sender_id, :recipient_id, :message, :attachment_url, :attachment_name, :attachment_mime, :attachment_size, SYSUTCDATETIME(), NULL)'
            );
            $insert->execute([
                'commission_id' => $commissionId,
                'sender_id' => $userId,
                'recipient_id' => $recipientId,
                'message' => $messageText,
                'attachment_url' => $attachmentUrl,
                'attachment_name' => $attachmentName,
                'attachment_mime' => $attachmentMime,
                'attachment_size' => $attachmentSize,
            ]);
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO dbo.commission_messages
                 (commission_id, sender_id, recipient_id, [message], sent_at, read_at)
                 OUTPUT INSERTED.id
                 VALUES (:commission_id, :sender_id, :recipient_id, :message, SYSUTCDATETIME(), NULL)'
            );
            $insert->execute([
                'commission_id' => $commissionId,
                'sender_id' => $userId,
                'recipient_id' => $recipientId,
                'message' => $messageText,
            ]);
        }
        $messageId = (int)$insert->fetchColumn();
        if ($messageId <= 0) throw new RuntimeException('Message was inserted but SQL Server did not return its new ID.');
        $pdo->commit();

        safe_notify($pdo, $recipientId, "New message on commission #{$commissionId}.", $commissionId);
        safe_audit_log($pdo, $userId, actor_name($user), 'Sent Direct Message', "Commission #{$commissionId}");
        safe_integration_log($pdo, 'POST /api/messages/send', 201, [
            'commissionId' => $commissionId,
            'messageId' => $messageId,
            'recipientId' => $recipientId,
            'hasAttachment' => $asset !== null,
        ]);

        json_response(true, 'Message sent.', ['id' => $messageId, 'attachmentUrl' => $attachmentUrl], 201);
    }

    if ($action === 'mark_read') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);
        $messageId = int_input($input, 'id', 1);
        $stmt = $pdo->prepare('UPDATE dbo.commission_messages SET read_at = SYSUTCDATETIME() WHERE id = :id AND recipient_id = :user_id AND read_at IS NULL');
        $stmt->execute(['id' => $messageId, 'user_id' => (int)$user['id']]);
        json_response(true, 'Message marked as read.');
    }

    if ($action === 'mark_thread_read') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);
        $commissionId = int_input($input, 'commissionId', 1);
        $stmt = $pdo->prepare(
            'UPDATE m SET read_at = SYSUTCDATETIME()
             FROM dbo.commission_messages AS m
             INNER JOIN dbo.commissions AS c ON c.id = m.commission_id
             WHERE m.commission_id = :commission_id AND m.recipient_id = :recipient_user_id AND m.read_at IS NULL
               AND (c.client_id = :client_user_id OR c.artist_id = :artist_user_id)'
        );
        $stmt->execute(['commission_id' => $commissionId, 'recipient_user_id' => (int)$user['id'], 'client_user_id' => (int)$user['id'], 'artist_user_id' => (int)$user['id']]);
        json_response(true, 'Conversation marked as read.');
    }

    json_response(false, 'Unknown message action.', null, 404);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    json_response(false, safe_error_message($e), null, 500);
}
