<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $pdo = db();
    $action = $_GET['action'] ?? '';

    if ($action === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $q = trim((string)($_GET['q'] ?? ''));
        $category = trim((string)($_GET['category'] ?? ''));

        $sql = 'SELECT id, artist_id, title, category, price, delivery_days,
                       slots_total, slots_used, description, cover_image_url, cover_image_name, cover_image_mime, cover_image_size, created_at, updated_at
                FROM dbo.listings';
        $where = [];
        $params = [];

        if ($category !== '' && $category !== 'All') {
            $where[] = 'category = :category';
            $params['category'] = $category;
        }

        if ($q !== '') {
            $where[] = '(title LIKE :q OR description LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC, id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $result = [];
        foreach ($stmt as $row) {
            $result[] = listing_view($row);
        }

        json_response(true, 'Listings loaded.', ['listings' => $result]);
    }

    if ($action === 'create') {
        request_method('POST');
        $input = (($_SERVER['CONTENT_TYPE'] ?? '') && stripos((string)$_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false) ? $_POST : input_json();
        require_csrf($input);
        $user = require_role($pdo, 'artist');

        $title = str_input($input, 'title', 160);
        if (mb_strlen($title) < 3) {
            json_response(false, 'Title must contain at least 3 characters.', null, 400);
        }

        $category = str_input($input, 'category', 40);
        $allowedCategories = ['Illustration', 'Live2D', '3D Asset', 'Animation'];
        if (!in_array($category, $allowedCategories, true)) {
            json_response(false, 'Invalid listing category.', null, 400);
        }

        $price = decimal_input($input, 'price', 5.00, 1000000.00);
        $deliveryDays = int_input($input, 'deliveryDays', 1, 365);
        $slotsTotal = int_input($input, 'slotsTotal', 1, 1000);
        $description = str_input($input, 'description', 5000);

        $cover = null;
        if (isset($_FILES['cover']) && (int)$_FILES['cover']['error'] !== UPLOAD_ERR_NO_FILE) {
            $cover = validate_uploaded_art_asset($_FILES['cover']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO dbo.listings
             (artist_id, title, category, price, delivery_days, slots_total, slots_used, description, cover_image_url, cover_image_name, cover_image_mime, cover_image_size, created_at, updated_at)
             OUTPUT INSERTED.id
             VALUES
             (:artist_id, :title, :category, :price, :delivery_days, :slots_total, 0, :description, NULL, NULL, NULL, NULL, SYSUTCDATETIME(), SYSUTCDATETIME())'
        );
        $stmt->execute([
            'artist_id' => (int)$user['id'],
            'title' => $title,
            'category' => $category,
            'price' => $price,
            'delivery_days' => $deliveryDays,
            'slots_total' => $slotsTotal,
            'description' => $description,
        ]);

        $listingId = (int)$stmt->fetchColumn();
        if ($listingId <= 0) {
            throw new RuntimeException('Tier listing was inserted but SQL Server did not return its new ID.');
        }

        if ($cover !== null) {
            $coverUrl = save_uploaded_art_asset($cover, $listingId, 'listing_covers');
            $coverStmt = $pdo->prepare('UPDATE dbo.listings SET cover_image_url = :url, cover_image_name = :name, cover_image_mime = :mime, cover_image_size = :size, updated_at = SYSUTCDATETIME() WHERE id = :id');
            $coverStmt->execute([
                'url' => $coverUrl, 'name' => $cover['originalName'], 'mime' => $cover['mimeType'], 'size' => $cover['size'], 'id' => $listingId
            ]);
        }

        try {
            audit_log($pdo, (int)$user['id'], actor_name($user), 'Published Tier Listing', $title);
        } catch (Throwable $logError) {
            error_log($logError->getMessage());
        }
        try {
            integration_log($pdo, 'POST /api/listings/create', 201, ['id' => $listingId]);
        } catch (Throwable $logError) {
            error_log($logError->getMessage());
        }

        $rowStmt = $pdo->prepare(
            'SELECT TOP (1) id, artist_id, title, category, price, delivery_days,
                    slots_total, slots_used, description, cover_image_url, cover_image_name, cover_image_mime, cover_image_size, created_at, updated_at
             FROM dbo.listings
             WHERE id = :id'
        );
        $rowStmt->execute(['id' => $listingId]);

        json_response(true, 'Listing published.', [
            'listing' => listing_view($rowStmt->fetch()),
        ], 201);
    }

    json_response(false, 'Unknown listing action.', null, 404);
} catch (Throwable $e) {
    json_response(false, safe_error_message($e), null, 500);
}
