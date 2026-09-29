<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$config = artic_local_config();
$driverLoaded = extension_loaded('pdo_sqlsrv');
$dbCfg = function_exists('artic_connection_config') ? artic_connection_config() : [];
$configReady = !empty($dbCfg['server']) && !empty($dbCfg['database']) && (
    !empty($dbCfg['trusted_connection']) || (!empty($dbCfg['username']) && !empty($dbCfg['password']))
);
$paypalClientConfigured = trim((string)artic_config_value('PAYPAL_CLIENT_ID', 'paypal_client_id', '')) !== '';
$paypalSecretConfigured = trim((string)artic_config_value('PAYPAL_CLIENT_SECRET', 'paypal_client_secret', '')) !== '';

try {
    if (!$driverLoaded) {
        throw new RuntimeException('PDO_SQLSRV is not enabled in this PHP runtime.');
    }

    $pdo = db();
    $stmt = $pdo->query('SELECT DB_NAME() AS database_name, @@SERVERNAME AS server_name');
    $info = $stmt->fetch();

    $missing = function_exists('required_schema_objects')
        ? required_schema_objects($pdo)
        : [];

    echo json_encode([
        'ok' => true,
        'ready' => count($missing) === 0,
        'database' => $info['database_name'] ?? null,
        'server' => $info['server_name'] ?? null,
        'pdo_driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
        'pdo_sqlsrv_loaded' => true,
        'config_present' => $configReady,
        'missing_schema_objects' => $missing,
        'paypal_configured' => $paypalClientConfigured && $paypalSecretConfigured,
        'paypal_mode' => strtolower((string)artic_config_value('PAYPAL_MODE', 'paypal_mode', 'sandbox')),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log($e->getMessage());
    echo json_encode([
        'ok' => false,
        'ready' => false,
        'pdo_sqlsrv_loaded' => $driverLoaded,
        'config_present' => $configReady,
        'paypal_configured' => $paypalClientConfigured && $paypalSecretConfigured,
        'paypal_mode' => strtolower((string)artic_config_value('PAYPAL_MODE', 'paypal_mode', 'sandbox')),
        'error' => 'Database health check failed.',
        'details' => $e->getMessage(),
        'next_step' => !$driverLoaded
            ? 'Enable PDO_SQLSRV in the PHP runtime used by localhost.'
            : (!$configReady ? 'Open setup.php and save your SQL Server connection settings.' : 'Open setup.php and test/repair the Artic database.'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
