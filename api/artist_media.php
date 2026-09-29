<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

try {
    $pdo = db();
    $action = $_GET['action'] ?? '';
    $user = current_user($pdo);

    if ($action === 'portfolio_list') {
        $artistId = int_input($_GET, 'artistId', 1);
        $stmt = $pdo->prepare('SELECT id, artist_id, title, description, url, file_name, mime_type, file_size, created_at FROM dbo.artist_portfolio WHERE artist_id = :artist_id ORDER BY created_at DESC, id DESC');
        $stmt->execute(['artist_id' => $artistId]);
        $items = [];
        foreach ($stmt as $row) {
            $mime = (string)($row['mime_type'] ?? '');
            $items[] = [
                'id' => (int)$row['id'],
                'artistId' => (int)$row['artist_id'],
                'title' => (string)$row['title'],
                'description' => (string)($row['description'] ?? ''),
                'url' => (string)$row['url'],
                'fileName' => $row['file_name'] ? (string)$row['file_name'] : null,
                'mimeType' => $row['mime_type'] ? $mime : null,
                'fileSize' => $row['file_size'] === null ? null : (int)$row['file_size'],
                'isImage' => str_starts_with($mime, 'image/') && $mime !== 'image/vnd.adobe.photoshop',
                'isPsd' => in_array(strtolower($mime), ['image/vnd.adobe.photoshop','image/x-photoshop','application/photoshop'], true),
                'createdAt' => iso_utc((string)$row['created_at']),
            ];
        }
        json_response(true, 'Portfolio loaded.', ['portfolio' => $items]);
    }

    if ($action === 'add_portfolio') {
        request_method('POST');
        require_role($pdo, 'artist');
        $input = $_POST;
        require_csrf($input);
        $artist = current_user($pdo);
        $title = str_input($input, 'title', 160);
        $description = str_input($input, 'description', 1000);
        if (!isset($_FILES['file'])) json_response(false, 'Choose a portfolio image or PSD.', null, 400);
        $asset = validate_uploaded_art_asset($_FILES['file']);
        $url = save_uploaded_art_asset($asset, (int)$artist['id'], 'artist_portfolio');
        $stmt = $pdo->prepare('INSERT INTO dbo.artist_portfolio (artist_id, title, description, url, file_name, mime_type, file_size, created_at) OUTPUT INSERTED.id VALUES (:artist_id,:title,:description,:url,:file_name,:mime_type,:file_size,SYSUTCDATETIME())');
        $stmt->execute([
            'artist_id' => (int)$artist['id'], 'title' => $title, 'description' => $description,
            'url' => $url, 'file_name' => $asset['originalName'], 'mime_type' => $asset['mimeType'], 'file_size' => $asset['size']
        ]);
        json_response(true, 'Portfolio item added.', ['id' => (int)$stmt->fetchColumn()], 201);
    }

    if ($action === 'delete_portfolio') {
        request_method('POST');
        require_role($pdo, 'artist');
        $input = input_json(); require_csrf($input);
        $artist = current_user($pdo); $id = int_input($input, 'id', 1);
        $stmt = $pdo->prepare('DELETE FROM dbo.artist_portfolio WHERE id = :id AND artist_id = :artist_id');
        $stmt->execute(['id' => $id, 'artist_id' => (int)$artist['id']]);
        if ($stmt->rowCount() !== 1) json_response(false, 'Portfolio item not found.', null, 404);
        json_response(true, 'Portfolio item removed.');
    }

    json_response(false, 'Unknown artist media action.', null, 404);
} catch (Throwable $e) {
    json_response(false, safe_error_message($e), null, 500);
}
