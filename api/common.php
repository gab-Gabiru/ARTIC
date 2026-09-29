<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

function start_artic_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function json_response(bool $success, string $message = '', mixed $data = null, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    exit;
}

function request_method(string $expected): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($expected)) {
        header('Allow: ' . strtoupper($expected));
        json_response(false, 'Method not allowed.', null, 405);
    }
}

function input_json(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return $_POST;
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        json_response(false, 'Request body must be valid JSON.', null, 400);
    }

    return $decoded;
}

function str_input(array $input, string $key, int $maxLength = 255, bool $required = true): string
{
    $value = trim((string)($input[$key] ?? ''));

    if ($required && $value === '') {
        json_response(false, ucfirst($key) . ' is required.', null, 400);
    }

    if (mb_strlen($value) > $maxLength) {
        json_response(false, ucfirst($key) . " must be {$maxLength} characters or fewer.", null, 400);
    }

    return $value;
}

function int_input(array $input, string $key, int $min = 1, ?int $max = null): int
{
    if (!isset($input[$key]) || filter_var($input[$key], FILTER_VALIDATE_INT) === false) {
        json_response(false, ucfirst($key) . ' must be a valid integer.', null, 400);
    }

    $value = (int)$input[$key];

    if ($value < $min || ($max !== null && $value > $max)) {
        json_response(false, ucfirst($key) . ' is outside the allowed range.', null, 400);
    }

    return $value;
}

function decimal_input(array $input, string $key, float $min = 0.01, float $max = 100000000): float
{
    if (!isset($input[$key]) || !is_numeric($input[$key])) {
        json_response(false, ucfirst($key) . ' must be a valid number.', null, 400);
    }

    $value = (float)$input[$key];

    if (!is_finite($value) || $value < $min || $value > $max) {
        json_response(false, ucfirst($key) . ' is outside the allowed range.', null, 400);
    }

    return round($value, 2);
}

function require_csrf(array $input): void
{
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $requestToken = (string)($input['csrf'] ?? '');

    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        json_response(false, 'Invalid or missing CSRF token.', null, 403);
    }
}

function ensure_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function current_user(PDO $pdo): ?array
{
    $id = $_SESSION['user_id'] ?? null;

    if ($id === null || filter_var($id, FILTER_VALIDATE_INT) === false) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT TOP (1) id, name, email, role, bio, profile_image_url, profile_image_name, profile_image_mime, profile_image_size, is_verified, verified_by_code, created_at, updated_at
         FROM dbo.users
         WHERE id = :id'
    );
    $stmt->execute(['id' => (int)$id]);

    $user = $stmt->fetch();

    return $user ?: null;
}

function require_auth(PDO $pdo): array
{
    $user = current_user($pdo);

    if (!$user) {
        json_response(false, 'Authentication required.', null, 401);
    }

    return $user;
}

function require_role(PDO $pdo, string $role): array
{
    $user = require_auth($pdo);

    if ($user['role'] !== $role) {
        json_response(false, 'This action is not available for your account role.', null, 403);
    }

    return $user;
}

function public_user(array $row, bool $includeEmail = false): array
{
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'email' => $includeEmail ? (string)$row['email'] : null,
        'role' => (string)$row['role'],
        'bio' => $row['bio'],
        'profileImageUrl' => $row['profile_image_url'] ?? null,
        'profileImageName' => $row['profile_image_name'] ?? null,
        'profileImageMime' => $row['profile_image_mime'] ?? null,
        'profileImageSize' => isset($row['profile_image_size']) && $row['profile_image_size'] !== null ? (int)$row['profile_image_size'] : null,
        'profileIsImage' => isset($row['profile_image_mime']) && is_string($row['profile_image_mime']) && str_starts_with($row['profile_image_mime'], 'image/') && strtolower((string)$row['profile_image_mime']) !== 'image/vnd.adobe.photoshop',
        'isVerified' => (bool)$row['is_verified'],
        'verifiedByCode' => $row['verified_by_code'],
        'joined' => iso_utc((string)$row['created_at']),
        'portfolio' => $row['portfolio'] ?? [],
    ];
}

function iso_utc(string $value): string
{
    if ($value === '') {
        return $value;
    }

    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $dt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    } catch (Throwable) {
        return $value;
    }
}

function listing_view(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'artistId' => (int)$row['artist_id'],
        'title' => (string)$row['title'],
        'category' => (string)$row['category'],
        'price' => (float)$row['price'],
        'deliveryDays' => (int)$row['delivery_days'],
        'slotsTotal' => (int)$row['slots_total'],
        'slotsUsed' => (int)$row['slots_used'],
        'description' => (string)$row['description'],
        'createdAt' => iso_utc((string)$row['created_at']),
        'updatedAt' => iso_utc((string)$row['updated_at']),
        'coverImageUrl' => $row['cover_image_url'] ?? null,
        'coverImageName' => $row['cover_image_name'] ?? null,
        'coverImageMime' => $row['cover_image_mime'] ?? null,
        'coverImageSize' => isset($row['cover_image_size']) && $row['cover_image_size'] !== null ? (int)$row['cover_image_size'] : null,
        'coverIsImage' => isset($row['cover_image_mime']) && is_string($row['cover_image_mime']) && str_starts_with($row['cover_image_mime'], 'image/') && strtolower((string)$row['cover_image_mime']) !== 'image/vnd.adobe.photoshop',
        'coverIsPsd' => isset($row['cover_image_mime']) && is_string($row['cover_image_mime']) && in_array(strtolower((string)$row['cover_image_mime']), ['image/vnd.adobe.photoshop','image/x-photoshop','application/photoshop'], true),
    ];
}

function commission_view(array $row, array $files, array $comments, array $history = []): array
{
    return [
        'id' => (int)$row['id'],
        'listingId' => (int)$row['listing_id'],
        'clientId' => (int)$row['client_id'],
        'artistId' => (int)$row['artist_id'],
        'status' => (string)$row['status'],
        'paymentStatus' => (string)$row['payment_status'],
        'price' => (float)$row['price'],
        'brief' => (string)$row['brief'],
        'revisions' => (int)$row['revisions'],
        'createdAt' => iso_utc((string)$row['created_at']),
        'updatedAt' => iso_utc((string)$row['updated_at']),
        'files' => $files,
        'comments' => $comments,
        'history' => $history,
        'invoice' => $row['invoice'] ?? null,
        'payout' => $row['payout'] ?? null,
        'paypalTransaction' => $row['paypalTransaction'] ?? null,
    ];
}

function invoice_view(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'commissionId' => (int)$row['commission_id'],
        'invoiceNumber' => (string)$row['invoice_number'],
        'clientId' => (int)$row['client_id'],
        'artistId' => (int)$row['artist_id'],
        'subtotal' => (float)$row['subtotal'],
        'platformFee' => (float)$row['platform_fee'],
        'total' => (float)$row['total'],
        'status' => (string)$row['status'],
        'issuedAt' => iso_utc((string)$row['issued_at']),
        'paidAt' => $row['paid_at'] ? iso_utc((string)$row['paid_at']) : null,
    ];
}

function payout_view(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'commissionId' => (int)$row['commission_id'],
        'invoiceId' => (int)$row['invoice_id'],
        'artistId' => (int)$row['artist_id'],
        'amount' => (float)$row['amount'],
        'status' => (string)$row['status'],
        'reference' => (string)$row['payout_reference'],
        'createdAt' => iso_utc((string)$row['created_at']),
        'paidAt' => $row['paid_at'] ? iso_utc((string)$row['paid_at']) : null,
    ];
}


function file_view(array $row): array
{
    $mime = $row['mime_type'] ?? null;
    $fileName = $row['file_name'] ?? null;
    return [
        'id' => (int)$row['id'],
        'type' => (string)$row['type'],
        'label' => (string)$row['label'],
        'url' => (string)$row['url'],
        'version' => (int)($row['version_no'] ?? 1),
        'addedAt' => iso_utc((string)$row['created_at']),
        'by' => (int)$row['added_by'],
        'fileName' => $fileName === null ? null : (string)$fileName,
        'mimeType' => $mime === null ? null : (string)$mime,
        'fileSize' => $row['file_size'] === null ? null : (int)$row['file_size'],
        'isImage' => is_string($mime) && str_starts_with($mime, 'image/') && strtolower((string)$mime) !== 'image/vnd.adobe.photoshop',
        'isPsd' => is_string($mime) && in_array(strtolower((string)$mime), ['image/vnd.adobe.photoshop', 'image/x-photoshop', 'application/photoshop'], true),
    ];
}

function upload_error_message(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file is too large for the server upload limits.',
        UPLOAD_ERR_PARTIAL => 'The file upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server is missing its PHP temporary upload directory.',
        UPLOAD_ERR_CANT_WRITE => 'PHP could not write the uploaded file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
        default => 'The file upload failed.',
    };
}

function validate_uploaded_art_asset(array $file): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        json_response(false, 'Invalid uploaded file.', null, 400);
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        json_response(false, upload_error_message((int)$file['error']), null, 400);
    }

    $maxBytes = 50 * 1024 * 1024;
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        json_response(false, 'The uploaded file is empty.', null, 400);
    }
    if ($size > $maxBytes) {
        json_response(false, 'Files are limited to 50 MB.', null, 413);
    }

    $original = trim((string)($file['name'] ?? 'asset'));
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'psd'];
    if (!in_array($ext, $allowed, true)) {
        json_response(false, 'Unsupported file type. Use JPG, PNG, GIF, WEBP, SVG, or PSD.', null, 415);
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        json_response(false, 'The uploaded file could not be verified by PHP.', null, 400);
    }

    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $tmp);
            if (is_string($detected) && $detected !== '') $mime = $detected;
            finfo_close($finfo);
        }
    }

    if ($ext === 'psd') {
        $fh = @fopen($tmp, 'rb');
        $signature = $fh ? fread($fh, 4) : false;
        if (is_resource($fh)) fclose($fh);
        if ($signature !== '8BPS') {
            json_response(false, 'The selected file has a PSD extension but is not a valid PSD document.', null, 415);
        }
        $mime = 'image/vnd.adobe.photoshop';
    } else {
        $validImageMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if ($ext !== 'svg' && !in_array($mime, $validImageMime, true)) {
            json_response(false, 'The uploaded image failed type validation.', null, 415);
        }
        if ($ext === 'svg') {
            $head = @file_get_contents($tmp, false, null, 0, 4096);
            if (!is_string($head) || stripos($head, '<svg') === false) {
                json_response(false, 'The uploaded SVG could not be validated.', null, 415);
            }
            $mime = 'image/svg+xml';
        }
    }

    return [
        'originalName' => $original,
        'extension' => $ext,
        'mimeType' => $mime,
        'size' => $size,
        'tmpName' => $tmp,
    ];
}

function validate_uploaded_profile_image(array $file): array
{
    $asset = validate_uploaded_art_asset($file);
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($asset['extension'], $allowed, true)) {
        json_response(false, 'Profile pictures must be JPG, PNG, GIF, or WEBP files.', null, 415);
    }
    return $asset;
}

function save_uploaded_art_asset(array $asset, int $commissionId, string $folder = 'commission_files'): string
{
    $folder = preg_replace('/[^a-zA-Z0-9_-]/', '', $folder) ?: 'commission_files';
    $baseDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        json_response(false, 'The upload directory could not be created.', null, 500);
    }

    $safeExt = $asset['extension'];
    $filename = 'commission_' . $commissionId . '_' . bin2hex(random_bytes(12)) . '.' . $safeExt;
    $destination = $baseDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($asset['tmpName'], $destination)) {
        json_response(false, 'The uploaded file could not be saved on the server.', null, 500);
    }

    return 'uploads/' . $folder . '/' . $filename;
}

function message_view(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'commissionId' => (int)$row['commission_id'],
        'senderId' => (int)$row['sender_id'],
        'recipientId' => (int)$row['recipient_id'],
        'message' => (string)$row['message'],
        'sentAt' => iso_utc((string)$row['sent_at']),
        'readAt' => $row['read_at'] === null ? null : iso_utc((string)$row['read_at']),
        'attachmentUrl' => $row['attachment_url'] ?? null,
        'attachmentName' => $row['attachment_name'] ?? null,
        'attachmentMime' => $row['attachment_mime'] ?? null,
        'attachmentSize' => isset($row['attachment_size']) && $row['attachment_size'] !== null ? (int)$row['attachment_size'] : null,
        'attachmentIsImage' => isset($row['attachment_mime']) && is_string($row['attachment_mime']) && str_starts_with($row['attachment_mime'], 'image/') && strtolower((string)$row['attachment_mime']) !== 'image/vnd.adobe.photoshop',
        'attachmentIsPsd' => isset($row['attachment_mime']) && is_string($row['attachment_mime']) && in_array(strtolower((string)$row['attachment_mime']), ['image/vnd.adobe.photoshop', 'image/x-photoshop', 'application/photoshop'], true),
    ];
}

function generate_invite_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    $makePart = static function () use ($alphabet): string {
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < 4; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    };

    return 'ARTIC-' . $makePart() . '-' . $makePart();
}

function create_invite_pair(PDO $pdo, int $creatorId): array
{
    $codes = [];

    while (count($codes) < 2) {
        $code = generate_invite_code();

        $check = $pdo->prepare('SELECT TOP (1) id FROM dbo.invite_codes WHERE code = :code');
        $check->execute(['code' => $code]);

        if ($check->fetch()) {
            continue;
        }

        $insert = $pdo->prepare(
            'INSERT INTO dbo.invite_codes (code, created_by, is_used, used_by, used_at)
             VALUES (:code, :created_by, 0, NULL, NULL)'
        );
        $insert->execute([
            'code' => $code,
            'created_by' => $creatorId,
        ]);

        $codes[] = $code;
    }

    return $codes;
}

function account_invite_codes(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, code, is_used, used_by, created_at, used_at
         FROM dbo.invite_codes
         WHERE created_by = :created_by
         ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute(['created_by' => $userId]);

    $result = [];
    foreach ($stmt as $row) {
        $result[] = [
            'id' => (int)$row['id'],
            'code' => $row['code'],
            'isUsed' => (bool)$row['is_used'],
            'usedBy' => $row['used_by'] === null ? null : (int)$row['used_by'],
            'createdAt' => iso_utc((string)$row['created_at']),
            'usedAt' => $row['used_at'] ? iso_utc((string)$row['used_at']) : null,
        ];
    }

    return $result;
}

function actor_name(array $user): string
{
    return (string)$user['name'];
}

function audit_log(PDO $pdo, ?int $actorId, string $actorName, string $action, string $target): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO dbo.audit_logs (actor_user_id, actor_name, action, target, created_at)
         VALUES (:actor_user_id, :actor_name, :action, :target, SYSUTCDATETIME())'
    );
    $stmt->execute([
        'actor_user_id' => $actorId,
        'actor_name' => $actorName,
        'action' => $action,
        'target' => $target,
    ]);
}

function integration_log(PDO $pdo, string $endpoint, int $statusCode, array $payload): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO dbo.integration_logs (endpoint, status_code, payload, created_at)
         VALUES (:endpoint, :status_code, :payload, SYSUTCDATETIME())'
    );
    $stmt->execute([
        'endpoint' => $endpoint,
        'status_code' => $statusCode,
        'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function notify(PDO $pdo, int $userId, string $message, ?int $commissionId = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO dbo.notifications (user_id, commission_id, message, is_read, created_at)
         VALUES (:user_id, :commission_id, :message, 0, SYSUTCDATETIME())'
    );
    $stmt->execute([
        'user_id' => $userId,
        'commission_id' => $commissionId,
        'message' => $message,
    ]);
}


function safe_audit_log(PDO $pdo, ?int $actorId, string $actorName, string $action, string $target): void
{
    try {
        if (table_exists($pdo, 'audit_logs')) {
            audit_log($pdo, $actorId, $actorName, $action, $target);
        }
    } catch (Throwable $e) {
        error_log('Audit log skipped: ' . $e->getMessage());
    }
}

function safe_integration_log(PDO $pdo, string $endpoint, int $statusCode, array $payload): void
{
    try {
        if (table_exists($pdo, 'integration_logs')) {
            integration_log($pdo, $endpoint, $statusCode, $payload);
        }
    } catch (Throwable $e) {
        error_log('Integration log skipped: ' . $e->getMessage());
    }
}

function safe_notify(PDO $pdo, int $userId, string $message, ?int $commissionId = null): void
{
    try {
        if (table_exists($pdo, 'notifications')) {
            notify($pdo, $userId, $message, $commissionId);
        }
    } catch (Throwable $e) {
        error_log('Notification skipped: ' . $e->getMessage());
    }
}


function table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $allowed = [
        'users',
        'invite_codes',
        'listings',
        'commissions',
        'commission_status_history',
        'commission_comments',
        'commission_messages',
        'notifications',
        'audit_logs',
        'integration_logs',
        'commission_files',
        'artist_portfolio',
        'invoices',
        'payouts',
        'paypal_transactions',
    ];

    if (!in_array($table, $allowed, true)) {
        return false;
    }

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("SELECT CASE WHEN OBJECT_ID(N'dbo.{$table}', N'U') IS NULL THEN 0 ELSE 1 END");
    $stmt->execute();
    return $cache[$table] = ((int)$stmt->fetchColumn() === 1);
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    if (!table_exists($pdo, $table)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT CASE WHEN EXISTS (
             SELECT 1
             FROM sys.columns
             WHERE object_id = OBJECT_ID(:qualified_table)
               AND name = :column_name
         ) THEN 1 ELSE 0 END'
    );
    $stmt->execute([
        'qualified_table' => 'dbo.' . $table,
        'column_name' => $column,
    ]);

    return (int)$stmt->fetchColumn() === 1;
}

function required_schema_objects(PDO $pdo): array
{
    $tables = [
        'users',
        'invite_codes',
        'listings',
        'commissions',
        'commission_status_history',
        'commission_files',
        'commission_comments',
        'commission_messages',
        'notifications',
        'audit_logs',
        'integration_logs',
        'artist_portfolio',
        'invoices',
        'payouts',
        'paypal_transactions',
    ];

    $missing = [];
    foreach ($tables as $table) {
        if (!table_exists($pdo, $table)) {
            $missing[] = 'table:' . $table;
        }
    }

    $columns = [
        ['users', 'id'],
        ['users', 'email'],
        ['users', 'password_hash'],
        ['users', 'role'],
        ['users', 'profile_image_url'],
        ['users', 'profile_image_mime'],
        ['listings', 'id'],
        ['listings', 'artist_id'],
        ['listings', 'price'],
        ['listings', 'slots_total'],
        ['listings', 'slots_used'],
        ['commissions', 'id'],
        ['commissions', 'listing_id'],
        ['commissions', 'client_id'],
        ['commissions', 'artist_id'],
        ['commissions', 'status'],
        ['commissions', 'payment_status'],
        ['commission_files', 'version_no'],
        ['commission_messages', 'message'],
        ['notifications', 'is_read'],
    ];

    foreach ($columns as [$table, $column]) {
        if (!column_exists($pdo, $table, $column)) {
            $missing[] = "column:{$table}.{$column}";
        }
    }

    return $missing;
}

function allowed_http_url(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
}

function safe_error_message(Throwable $e): string
{
    $detail = trim($e->getMessage());
    error_log($detail);

    // This app is intended for local PHP development. Returning the SQL error here
    // makes schema mismatches immediately visible instead of hiding the real cause
    // behind a generic toast. Do not use this pattern unchanged in production.
    if ($e instanceof PDOException && $detail !== '') {
        return 'Database error: ' . $detail;
    }

    return $detail !== '' ? $detail : 'The request could not be completed.';
}

start_artic_session();

// Do not mutate the SQL Server schema on every API request.
// The app is designed to run against an existing Artic database; optional
// support tables are checked by individual endpoints and may be installed
// once with REPAIR_EXISTING_ARTIC_DB.sql.
