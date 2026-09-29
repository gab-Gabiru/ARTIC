<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $pdo = db();
    if (!table_exists($pdo, 'notifications')) {
        json_response(false, 'Notifications are not installed in this Artic database. Run REPAIR_EXISTING_ARTIC_DB.sql or setup.php once, then reload.', null, 503);
    }
    $action = $_GET['action'] ?? '';
    $user = require_auth($pdo);

    if ($action === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $stmt = $pdo->prepare(
            'SELECT TOP (100) id, commission_id, message, is_read, created_at
             FROM dbo.notifications
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['user_id' => (int)$user['id']]);

        $items = [];
        foreach ($stmt as $row) {
            $items[] = [
                'id' => (int)$row['id'],
                'message' => $row['message'],
                'commissionId' => $row['commission_id'] === null ? null : (int)$row['commission_id'],
                'read' => (bool)$row['is_read'],
                'at' => iso_utc((string)$row['created_at']),
            ];
        }
        json_response(true, 'Notifications loaded.', ['notifications' => $items]);
    }

    if ($action === 'mark_read') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);
        $notificationId = int_input($input, 'id', 1);

        $stmt = $pdo->prepare(
            'UPDATE dbo.notifications
             SET is_read = 1
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            'id' => $notificationId,
            'user_id' => (int)$user['id'],
        ]);

        if ($stmt->rowCount() !== 1) {
            json_response(false, 'Notification not found.', null, 404);
        }

        integration_log($pdo, 'POST /api/notifications/mark_read', 200, ['id' => $notificationId, 'userId' => (int)$user['id']]);
        json_response(true, 'Notification marked as read.');
    }

    if ($action === 'mark_all_read') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);

        $stmt = $pdo->prepare(
            'UPDATE dbo.notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0'
        );
        $stmt->execute(['user_id' => (int)$user['id']]);
        integration_log($pdo, 'POST /api/notifications/mark_all_read', 200, ['userId' => (int)$user['id']]);
        json_response(true, 'Notifications marked as read.');
    }

    json_response(false, 'Unknown notification action.', null, 404);
} catch (Throwable $e) {
    json_response(false, safe_error_message($e), null, 500);
}
