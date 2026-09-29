<?php
// Local PHP built-in server router for Artic.
// Supports both:
//   http://localhost:PORT/index.php
//   http://localhost:PORT/Artic_MSSQL_Reliable_Fixed/index.php
// while the document root is the Artic project directory.

declare(strict_types=1);

$projectRoot = __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$prefix = '/Artic_MSSQL_Reliable_Fixed';

if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
    $path = substr($path, strlen($prefix));
    if ($path === '' || $path === false) {
        $path = '/';
    }
}

if ($path === '/') {
    $path = '/index.php';
}

// Normalize and prevent traversal.
$relative = ltrim(str_replace('\\', '/', $path), '/');
$relative = preg_replace('#/+#', '/', $relative) ?? $relative;
if ($relative === '' || str_contains($relative, '..')) {
    http_response_code(400);
    echo 'Bad request.';
    exit;
}

$file = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

if (!is_file($file)) {
    http_response_code(404);
    echo 'Requested resource not found.';
    exit;
}

$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if ($extension === 'php') {
    $_SERVER['SCRIPT_FILENAME'] = $file;
    $_SERVER['SCRIPT_NAME'] = '/' . $relative;
    require $file;
    exit;
}

$mime = match ($extension) {
    'css' => 'text/css; charset=UTF-8',
    'js' => 'application/javascript; charset=UTF-8',
    'json' => 'application/json; charset=UTF-8',
    'png' => 'image/png',
    'jpg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
    'txt' => 'text/plain; charset=UTF-8',
    'md' => 'text/markdown; charset=UTF-8',
    'pdf' => 'application/pdf',
    default => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
readfile($file);
