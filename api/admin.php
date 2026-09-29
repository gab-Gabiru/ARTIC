<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $pdo = db();
    $action = $_GET['action'] ?? '';
    $admin = require_role($pdo, 'admin');

    if (!table_exists($pdo, 'invite_codes') && in_array($action, ['verify_artist', 'generate_code'], true)) {
        json_response(false, 'Verification code storage is not installed. Run REPAIR_EXISTING_ARTIC_DB.sql or setup.php once, then reload.', null, 503);
    }

    if ($action === 'verify_artist') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);
        $userId = int_input($input, 'userId', 1);

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT TOP (1) id, name, role, is_verified
             FROM dbo.users WITH (UPDLOCK, ROWLOCK)
             WHERE id = :id'
        );
        $stmt->execute(['id' => $userId]);
        $target = $stmt->fetch();

        if (!$target) { $pdo->rollBack(); json_response(false, 'User account not found.', null, 404); }
        if ($target['role'] !== 'artist') { $pdo->rollBack(); json_response(false, 'Only artist accounts can be verified here.', null, 400); }
        if ((bool)$target['is_verified']) { $pdo->rollBack(); json_response(false, 'Artist account is already verified.', null, 409); }

        $update = $pdo->prepare(
            'UPDATE dbo.users
             SET is_verified = 1,
                 verified_by_code = :verified_by_code,
                 updated_at = SYSUTCDATETIME()
             WHERE id = :id AND is_verified = 0'
        );
        $update->execute([
            'verified_by_code' => 'ADMIN-DIRECT',
            'id' => $userId,
        ]);
        create_invite_pair($pdo, $userId);

        audit_log($pdo, (int)$admin['id'], actor_name($admin), 'Manually Verified Artist', $target['name']);
        integration_log($pdo, 'POST /api/admin/verify_artist', 200, ['userId' => $userId]);
        $pdo->commit();

        $updatedStmt = $pdo->prepare(
            'SELECT TOP (1) id, name, email, role, bio, is_verified, verified_by_code, created_at, updated_at
             FROM dbo.users WHERE id = :id'
        );
        $updatedStmt->execute(['id' => $userId]);
        $updated = $updatedStmt->fetch();

        json_response(true, 'Artist verified.', ['user' => public_user($updated, true)]);
    }

    if ($action === 'generate_code') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);

        do {
            $code = generate_invite_code();
            $check = $pdo->prepare('SELECT TOP (1) id FROM dbo.invite_codes WHERE code = :code');
            $check->execute(['code' => $code]);
        } while ($check->fetch());

        $insert = $pdo->prepare(
            'INSERT INTO dbo.invite_codes
             (code, created_by, is_used, used_by, created_at, used_at)
             VALUES (:code, :created_by, 0, NULL, SYSUTCDATETIME(), NULL)'
        );
        $insert->execute(['code' => $code, 'created_by' => (int)$admin['id']]);

        audit_log($pdo, (int)$admin['id'], actor_name($admin), 'Generated Master Verification Code', $code);
        integration_log($pdo, 'POST /api/admin/generate_code', 201, ['code' => $code]);

        json_response(true, 'Verification code created.', ['code' => $code], 201);
    }

    if ($action === 'change_role') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);
        $userId = int_input($input, 'userId', 1);
        $newRole = str_input($input, 'role', 20);

        if (!in_array($newRole, ['client', 'artist', 'admin'], true)) {
            json_response(false, 'Invalid role.', null, 400);
        }
        if ($userId === (int)$admin['id'] && $newRole !== 'admin') {
            json_response(false, 'You cannot remove your own admin role.', null, 400);
        }

        $stmt = $pdo->prepare(
            'SELECT TOP (1) id, name, role, is_verified
             FROM dbo.users
             WHERE id = :id'
        );
        $stmt->execute(['id' => $userId]);
        $target = $stmt->fetch();
        if (!$target) json_response(false, 'User account not found.', null, 404);

        $isVerified = ($newRole === 'admin') ? 1 : ((bool)$target['is_verified'] ? 1 : 0);
        $update = $pdo->prepare(
            'UPDATE dbo.users
             SET role = :role,
                 is_verified = :is_verified,
                 updated_at = SYSUTCDATETIME()
             WHERE id = :id'
        );
        $update->execute([
            'role' => $newRole,
            'is_verified' => $isVerified,
            'id' => $userId,
        ]);

        audit_log($pdo, (int)$admin['id'], actor_name($admin), 'Changed User Role', "{$target['name']}: {$target['role']} -> {$newRole}");
        integration_log($pdo, 'POST /api/admin/change_role', 200, ['userId' => $userId, 'role' => $newRole]);
        json_response(true, 'User role updated.');
    }

    json_response(false, 'Unknown admin action.', null, 404);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    json_response(false, safe_error_message($e), null, 500);
}
