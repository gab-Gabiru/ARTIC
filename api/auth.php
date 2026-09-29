<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $pdo = db();
    $action = $_GET['action'] ?? '';

    if ($action === 'login') {
        request_method('POST');
        $input = input_json();

        $email = strtolower(str_input($input, 'email', 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(false, 'Enter a valid email address.', null, 400);
        }

        $password = (string)($input['password'] ?? '');
        if ($password === '' || strlen($password) > 255) {
            json_response(false, 'A valid password is required.', null, 400);
        }

        $stmt = $pdo->prepare(
            'SELECT TOP (1)
                id, name, email, role, bio, profile_image_url, profile_image_name, profile_image_mime, profile_image_size, is_verified, verified_by_code,
                password_hash, created_at, updated_at
             FROM dbo.users
             WHERE LOWER(email) = :email'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            integration_log($pdo, 'POST /api/auth/login', 401, ['email' => $email, 'reason' => 'invalid_credentials']);
            json_response(false, 'Invalid email or password.', null, 401);
        }

        if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $pdo->prepare(
                'UPDATE dbo.users
                 SET password_hash = :password_hash,
                     updated_at = SYSUTCDATETIME()
                 WHERE id = :id'
            );
            $rehash->execute([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => (int)$user['id'],
            ]);
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $public = public_user($user, true);
        if ($user['role'] === 'artist') {
            $public['inviteCodes'] = account_invite_codes($pdo, (int)$user['id']);
        }

        integration_log($pdo, 'POST /api/auth/login', 200, [
            'userId' => (int)$user['id'],
            'role' => $user['role'],
        ]);

        json_response(true, 'Signed in successfully.', [
            'user' => $public,
            'csrf' => $_SESSION['csrf_token'],
        ]);
    }

    if ($action === 'register') {
        request_method('POST');
        $input = input_json();

        $name = str_input($input, 'name', 100);
        if (mb_strlen($name) < 2) {
            json_response(false, 'Name must contain at least 2 characters.', null, 400);
        }

        $email = strtolower(str_input($input, 'email', 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(false, 'Enter a valid email address.', null, 400);
        }

        $password = (string)($input['password'] ?? '');
        $confirmPassword = (string)($input['confirmPassword'] ?? '');

        if (strlen($password) < 8 || strlen($password) > 72) {
            json_response(false, 'Password must be between 8 and 72 characters.', null, 400);
        }

        if (!hash_equals($password, $confirmPassword)) {
            json_response(false, 'Passwords do not match.', null, 400);
        }

        $role = (string)($input['role'] ?? '');
        if (!in_array($role, ['client', 'artist'], true)) {
            json_response(false, 'Account role must be Client or Artist.', null, 400);
        }

        $inviteCode = strtoupper(trim((string)($input['inviteCode'] ?? '')));
        if (strlen($inviteCode) > 30) {
            json_response(false, 'Verification code is too long.', null, 400);
        }

        $pdo->beginTransaction();

        $exists = $pdo->prepare('SELECT TOP (1) id FROM dbo.users WHERE LOWER(email) = :email');
        $exists->execute(['email' => $email]);

        if ($exists->fetch()) {
            $pdo->rollBack();
            json_response(false, 'An account with that email already exists.', null, 409);
        }

        $isVerified = false;
        $verifiedByCode = null;
        $matchedCodeId = null;

        if ($role === 'artist' && $inviteCode !== '') {
            $codeStmt = $pdo->prepare(
                'SELECT TOP (1) id, code, is_used
                 FROM dbo.invite_codes WITH (UPDLOCK, ROWLOCK)
                 WHERE code = :code'
            );
            $codeStmt->execute(['code' => $inviteCode]);
            $code = $codeStmt->fetch();

            if (!$code || (bool)$code['is_used']) {
                $pdo->rollBack();
                json_response(false, 'Verification code is invalid or already used.', null, 400);
            }

            $isVerified = true;
            $verifiedByCode = $inviteCode;
            $matchedCodeId = (int)$code['id'];
        }

        $insertUser = $pdo->prepare(
            'INSERT INTO dbo.users
             (name, email, password_hash, role, bio, is_verified, verified_by_code, created_at, updated_at)
             OUTPUT INSERTED.id
             VALUES
             (:name, :email, :password_hash, :role, NULL, :is_verified, :verified_by_code, SYSUTCDATETIME(), SYSUTCDATETIME())'
        );
        $insertUser->execute([
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'is_verified' => $isVerified ? 1 : 0,
            'verified_by_code' => $verifiedByCode,
        ]);

        $newUserId = (int)$insertUser->fetchColumn();

        if ($matchedCodeId !== null) {
            $useCode = $pdo->prepare(
                'UPDATE dbo.invite_codes
                 SET is_used = 1, used_by = :used_by, used_at = SYSUTCDATETIME()
                 WHERE id = :id AND is_used = 0'
            );
            $useCode->execute([
                'used_by' => $newUserId,
                'id' => $matchedCodeId,
            ]);

            if ($useCode->rowCount() !== 1) {
                $pdo->rollBack();
                json_response(false, 'Verification code could not be redeemed. Please try again.', null, 409);
            }
        }

        if ($isVerified) {
            create_invite_pair($pdo, $newUserId);
        }

        audit_log(
            $pdo,
            $newUserId,
            $name,
            'Account Created',
            "Role: {$role}, Verified: " . ($isVerified ? 'Yes' : 'No')
        );

        integration_log($pdo, 'POST /api/auth/register', 201, [
            'userId' => $newUserId,
            'role' => $role,
            'verified' => $isVerified,
        ]);

        // Build the response from the values already validated/inserted.
        // This avoids a second database read after COMMIT becoming the reason
        // a successfully-created account looks like registration failed.
        $createdAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
        $public = [
            'id' => $newUserId,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'bio' => null,
            'isVerified' => $isVerified,
            'verifiedByCode' => $verifiedByCode,
            'joined' => $createdAt,
        ];

        $pdo->commit();

        session_regenerate_id(true);
        $_SESSION['user_id'] = $newUserId;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        if ($role === 'artist') {
            try {
                $public['inviteCodes'] = account_invite_codes($pdo, $newUserId);
            } catch (Throwable $inviteError) {
                error_log($inviteError->getMessage());
                $public['inviteCodes'] = [];
            }
        }

        json_response(true, 'Account created and signed in successfully.', [
            'user' => $public,
            'csrf' => $_SESSION['csrf_token'],
        ], 201);
    }

    if ($action === 'verify_artist') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);
        $user = require_role($pdo, 'artist');

        if ((bool)$user['is_verified']) {
            json_response(false, 'This artist account is already verified.', null, 409);
        }

        $codeValue = strtoupper(str_input($input, 'code', 30));

        $pdo->beginTransaction();

        $codeStmt = $pdo->prepare(
            'SELECT TOP (1) id, code, is_used
             FROM dbo.invite_codes WITH (UPDLOCK, ROWLOCK)
             WHERE code = :code'
        );
        $codeStmt->execute(['code' => $codeValue]);
        $code = $codeStmt->fetch();

        if (!$code || (bool)$code['is_used']) {
            $pdo->rollBack();
            json_response(false, 'Code is invalid, expired, or already redeemed.', null, 400);
        }

        $update = $pdo->prepare(
            'UPDATE dbo.users
             SET is_verified = 1,
                 verified_by_code = :verified_by_code,
                 updated_at = SYSUTCDATETIME()
             WHERE id = :id AND is_verified = 0'
        );
        $update->execute([
            'verified_by_code' => $codeValue,
            'id' => (int)$user['id'],
        ]);

        $useCode = $pdo->prepare(
            'UPDATE dbo.invite_codes
             SET is_used = 1, used_by = :used_by, used_at = SYSUTCDATETIME()
             WHERE id = :id AND is_used = 0'
        );
        $useCode->execute([
            'used_by' => (int)$user['id'],
            'id' => (int)$code['id'],
        ]);

        if ($update->rowCount() !== 1 || $useCode->rowCount() !== 1) {
            $pdo->rollBack();
            json_response(false, 'The verification code could not be redeemed.', null, 409);
        }

        $newCodes = create_invite_pair($pdo, (int)$user['id']);

        audit_log(
            $pdo,
            (int)$user['id'],
            actor_name($user),
            'Verified Artist via Code',
            "Code: {$codeValue}"
        );
        integration_log($pdo, 'POST /api/auth/verify_artist', 200, [
            'userId' => (int)$user['id'],
            'code' => $codeValue,
        ]);

        $pdo->commit();

        json_response(true, 'Artist account verified.', [
            'inviteCodes' => $newCodes,
        ]);
    }

    if ($action === 'update_profile') {
        request_method('POST');
        $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        $input = stripos($contentType, 'multipart/form-data') !== false ? $_POST : input_json();
        require_csrf($input);
        $user = require_auth($pdo);

        $name = str_input($input, 'name', 100);
        if (mb_strlen($name) < 2) {
            json_response(false, 'Name must contain at least 2 characters.', null, 400);
        }
        $bio = str_input($input, 'bio', 2000, false);

        $profile = null;
        if (isset($_FILES['profileImage']) && (int)$_FILES['profileImage']['error'] !== UPLOAD_ERR_NO_FILE) {
            $profile = validate_uploaded_profile_image($_FILES['profileImage']);
        }

        $profileUrl = $user['profile_image_url'] ?? null;
        $profileName = $user['profile_image_name'] ?? null;
        $profileMime = $user['profile_image_mime'] ?? null;
        $profileSize = $user['profile_image_size'] ?? null;

        if ($profile !== null) {
            $profileUrl = save_uploaded_art_asset($profile, (int)$user['id'], 'profiles');
            $profileName = $profile['originalName'];
            $profileMime = $profile['mimeType'];
            $profileSize = $profile['size'];
        }

        $stmt = $pdo->prepare(
            'UPDATE dbo.users
             SET name = :name,
                 bio = :bio,
                 profile_image_url = :profile_image_url,
                 profile_image_name = :profile_image_name,
                 profile_image_mime = :profile_image_mime,
                 profile_image_size = :profile_image_size,
                 updated_at = SYSUTCDATETIME()
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'bio' => ($bio === '' ? null : $bio),
            'profile_image_url' => $profileUrl,
            'profile_image_name' => $profileName,
            'profile_image_mime' => $profileMime,
            'profile_image_size' => $profileSize,
            'id' => (int)$user['id'],
        ]);

        $freshStmt = $pdo->prepare(
            'SELECT TOP (1) id, name, email, role, bio, profile_image_url, profile_image_name, profile_image_mime, profile_image_size, is_verified, verified_by_code, created_at, updated_at
             FROM dbo.users WHERE id = :id'
        );
        $freshStmt->execute(['id' => (int)$user['id']]);
        $fresh = $freshStmt->fetch();
        if (!$fresh) {
            json_response(false, 'Profile was updated but could not be reloaded.', null, 500);
        }

        $public = public_user($fresh, true);
        $portfolioStmt = null;
        if (table_exists($pdo, 'artist_portfolio') && $fresh['role'] === 'artist') {
            $portfolioStmt = $pdo->prepare('SELECT id, artist_id, title, description, url, file_name, mime_type, file_size, created_at FROM dbo.artist_portfolio WHERE artist_id = :artist_id ORDER BY created_at DESC, id DESC');
            $portfolioStmt->execute(['artist_id' => (int)$fresh['id']]);
            $portfolio = [];
            foreach ($portfolioStmt as $pRow) {
                $mime = (string)($pRow['mime_type'] ?? '');
                $portfolio[] = [
                    'id' => (int)$pRow['id'],
                    'artistId' => (int)$pRow['artist_id'],
                    'title' => (string)$pRow['title'],
                    'description' => (string)($pRow['description'] ?? ''),
                    'url' => (string)$pRow['url'],
                    'fileName' => $pRow['file_name'] ? (string)$pRow['file_name'] : null,
                    'mimeType' => $pRow['mime_type'] ? $mime : null,
                    'fileSize' => $pRow['file_size'] === null ? null : (int)$pRow['file_size'],
                    'isImage' => str_starts_with($mime, 'image/') && $mime !== 'image/vnd.adobe.photoshop',
                    'isPsd' => in_array(strtolower($mime), ['image/vnd.adobe.photoshop','image/x-photoshop','application/photoshop'], true),
                    'createdAt' => iso_utc((string)$pRow['created_at']),
                ];
            }
            $public['portfolio'] = $portfolio;
        }

        safe_audit_log($pdo, (int)$fresh['id'], actor_name($fresh), 'Updated Account Profile', 'Self');
        safe_integration_log($pdo, 'POST /api/auth/update_profile', 200, ['userId' => (int)$fresh['id']]);
        json_response(true, 'Account profile updated successfully.', ['user' => $public]);
    }

    if ($action === 'change_password') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);
        $user = require_auth($pdo);

        $currentPassword = (string)($input['currentPassword'] ?? '');
        $newPassword = (string)($input['newPassword'] ?? '');
        $confirmPassword = (string)($input['confirmPassword'] ?? '');

        if (!password_verify($currentPassword, (string)$user['password_hash'])) {
            json_response(false, 'Current password is incorrect.', null, 401);
        }

        if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            json_response(false, 'New password must be between 8 and 72 characters.', null, 400);
        }

        if (!hash_equals($newPassword, $confirmPassword)) {
            json_response(false, 'New passwords do not match.', null, 400);
        }

        $stmt = $pdo->prepare(
            'UPDATE dbo.users
             SET password_hash = :password_hash,
                 updated_at = SYSUTCDATETIME()
             WHERE id = :id'
        );
        $stmt->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => (int)$user['id'],
        ]);

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        audit_log($pdo, (int)$user['id'], actor_name($user), 'Changed Account Password', 'Self');
        integration_log($pdo, 'POST /api/auth/change_password', 200, ['userId' => (int)$user['id']]);

        json_response(true, 'Password changed successfully.', ['csrf' => $_SESSION['csrf_token']]);
    }

    if ($action === 'logout') {
        request_method('POST');
        $input = input_json();
        require_csrf($input);

        $user = current_user($pdo);
        if ($user) {
            integration_log($pdo, 'POST /api/auth/logout', 200, ['userId' => (int)$user['id']]);
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'] ?? '',
                (bool)$params['secure'],
                (bool)$params['httponly']
            );
        }

        session_destroy();
        json_response(true, 'Signed out successfully.');
    }

    json_response(false, 'Unknown authentication action.', null, 404);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response(false, safe_error_message($e), null, 500);
}
