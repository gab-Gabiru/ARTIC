<?php

declare(strict_types=1);

/**
 * Artic SQL Server connection layer.
 *
 * Local development is deliberately self-contained so Apache/localhost and
 * `php -S` behave the same. Configuration precedence:
 *   1. Process environment variables
 *   2. config.local.php in this project directory
 *   3. Safe local defaults
 *
 * Supported settings:
 *   server                  e.g. localhost\\SQLEXPRESS03
 *   port                    optional TCP port; when set, server becomes tcp:server,port
 *   database                artic
 *   username                sa (SQL authentication)
 *   password                SQL password
 *   trusted_connection      true for Windows Authentication
 *   encrypt                 true/false; local fallback also tries false
 *   trust_server_certificate true/false
 *   login_timeout           seconds
 */

function artic_local_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $config = [];
    $localFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
    if (is_file($localFile)) {
        $loaded = require $localFile;
        if (is_array($loaded)) {
            $config = $loaded;
        }
    }
    return $config;
}

function artic_running_on_vercel(): bool
{
    $vercel = getenv('VERCEL');
    return ($vercel !== false && $vercel === '1') || getenv('VERCEL_ENV') !== false;
}

function artic_config_value(string $envKey, string $configKey, mixed $default = null): mixed
{
    $env = getenv($envKey);

    // Production/Vercel configuration must come from environment variables so
    // secrets are not baked into the image. Local development keeps the
    // checked-local config.local.php convenience.
    if (artic_running_on_vercel() && $env !== false && $env !== '') {
        return $env;
    }

    $local = artic_local_config();
    if (array_key_exists($configKey, $local) && $local[$configKey] !== '') {
        return $local[$configKey];
    }

    if ($env !== false && $env !== '') {
        return $env;
    }

    return $default;
}

function artic_bool_value(string $envKey, string $configKey, bool $default = false): bool
{
    $value = artic_config_value($envKey, $configKey, $default);
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function artic_int_value(string $envKey, string $configKey, int $default): int
{
    $value = artic_config_value($envKey, $configKey, $default);
    return max(1, (int)$value);
}

/** @return array<string,mixed> */
function artic_connection_config(): array
{
    $server = trim((string)artic_config_value(
        'ARTIC_DB_SERVER',
        'server',
        'localhost\\SQLEXPRESS03'
    ));
    if (artic_running_on_vercel() && $server === '') {
        throw new RuntimeException('ARTIC_DB_SERVER is required on Vercel. Use a public SQL Server hostname or IP.');
    }
    $port = trim((string)artic_config_value('ARTIC_DB_PORT', 'port', ''));
    $database = trim((string)artic_config_value('ARTIC_DB_NAME', 'database', 'artic'));
    $username = (string)artic_config_value('ARTIC_DB_USER', 'username', 'sa');
    $password = artic_config_value('ARTIC_DB_PASS', 'password', null);
    if (is_string($password) && trim($password) === 'PUT_YOUR_SQL_SERVER_PASSWORD_HERE') {
        $password = null;
    }

    return [
        'server' => $server,
        'port' => $port,
        'database' => $database,
        'username' => $username,
        'password' => $password,
        'trusted_connection' => artic_bool_value('ARTIC_DB_TRUSTED_CONNECTION', 'trusted_connection', false),
        // Local SQL Server Express commonly uses a self-signed certificate or
        // an older configuration. We try encrypted + trusted first, then an
        // unencrypted local-development connection if necessary.
        'encrypt' => artic_bool_value('ARTIC_DB_ENCRYPT', 'encrypt', true),
        'trust_server_certificate' => artic_bool_value(
            'ARTIC_DB_TRUST_SERVER_CERTIFICATE',
            'trust_server_certificate',
            true
        ),
        'login_timeout' => artic_int_value('ARTIC_DB_LOGIN_TIMEOUT', 'login_timeout', 8),
        'allow_windows_fallback' => artic_bool_value('ARTIC_DB_ALLOW_WINDOWS_FALLBACK', 'allow_windows_fallback', true),
    ];
}

/** @return list<string> */
function artic_server_candidates(array $cfg): array
{
    $server = trim((string)$cfg['server']);
    $port = trim((string)$cfg['port']);

    if ($port !== '') {
        return ['tcp:' . preg_replace('/^tcp:/i', '', $server) . ',' . $port];
    }

    $candidates = [$server];

    // If the original project used a machine-specific name, keep it as a
    // fallback but also try local aliases so the app works on localhost on
    // another machine.
    if (str_contains($server, '\\')) {
        [, $instance] = explode('\\', $server, 2);
        $instance = trim($instance);
        if ($instance !== '') {
            foreach ([
                'localhost\\' . $instance,
                '.\\' . $instance,
                '(local)\\' . $instance,
                gethostname() . '\\' . $instance,
            ] as $candidate) {
                if ($candidate !== '') {
                    $candidates[] = $candidate;
                }
            }
        }
    }

    $normalized = [];
    foreach ($candidates as $candidate) {
        if (!in_array($candidate, $normalized, true)) {
            $normalized[] = $candidate;
        }
    }
    return $normalized;
}

/** @return list<array{server:string,encrypt:bool,trust:bool}> */
function artic_connection_profiles(array $cfg): array
{
    $profiles = [];
    foreach (artic_server_candidates($cfg) as $server) {
        $profiles[] = [
            'server' => $server,
            'encrypt' => (bool)$cfg['encrypt'],
            'trust' => (bool)$cfg['trust_server_certificate'],
        ];

        // For localhost named-instance development, retry without TLS if the
        // ODBC/TLS layer rejects the connection. This is not the default for
        // production; it exists to tolerate SQL Server Express dev installs.
        $lowerServer = strtolower($server);
        $isLocalNamedInstance = str_starts_with($lowerServer, 'localhost\\')
            || str_starts_with($lowerServer, '127.0.0.1\\')
            || str_starts_with($lowerServer, '.\\')
            || str_starts_with($lowerServer, '(local)\\');
        if ($isLocalNamedInstance || str_starts_with($lowerServer, 'tcp:')) {
            if ((bool)$cfg['encrypt']) {
                $profiles[] = [
                    'server' => $server,
                    'encrypt' => false,
                    'trust' => true,
                ];
            }
        }
    }
    return $profiles;
}

function artic_make_dsn(array $cfg, string $server, bool $encrypt, bool $trust): string
{
    return sprintf(
        'sqlsrv:Server=%s;Database=%s;Encrypt=%s;TrustServerCertificate=%s;LoginTimeout=%d',
        $server,
        $cfg['database'],
        $encrypt ? 'true' : 'false',
        $trust ? 'true' : 'false',
        (int)$cfg['login_timeout']
    );
}

/** @return array{pdo:PDO,profile:array{server:string,encrypt:bool,trust:bool,auth:string}} */
function artic_connect_with_config(array $cfg): array
{
    if (!extension_loaded('pdo_sqlsrv')) {
        throw new RuntimeException(
            'PDO_SQLSRV is not enabled. Enable the Microsoft SQL Server PDO driver in the PHP installation serving localhost.'
        );
    }

    if ($cfg['database'] === '') {
        throw new RuntimeException('SQL Server database name is empty. Expected database: artic.');
    }

    $errors = [];
    $profiles = artic_connection_profiles($cfg);

    // Localhost-first strategy: Windows Authentication is attempted first for
    // local SQL Server Express. This avoids a bad/stale sa password preventing
    // the application from booting when the Windows account already has access.
    $isLocal = true;
    foreach ($profiles as $profile) {
        $lower = strtolower($profile['server']);
        if (!(str_starts_with($lower, 'localhost') || str_starts_with($lower, '.\\') || str_starts_with($lower, '(local)') || str_starts_with($lower, '127.0.0.1') || strtolower($profile['server']) === strtolower((string)gethostname()))) {
            $isLocal = false;
            break;
        }
    }

    $tryWindows = $isLocal && ($cfg['trusted_connection'] || !empty($cfg['allow_windows_fallback']));
    if ($tryWindows) {
        foreach ($profiles as $profile) {
            $dsn = artic_make_dsn($cfg, $profile['server'], $profile['encrypt'], $profile['trust']);
            try {
                $pdo = new PDO(
                    $dsn,
                    null,
                    null,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
                    ]
                );
                $profile['auth'] = 'windows';
                return ['pdo' => $pdo, 'profile' => $profile];
            } catch (PDOException $e) {
                $errors[] = sprintf(
                    '%s | encrypt=%s | auth=windows | %s',
                    $profile['server'],
                    $profile['encrypt'] ? 'true' : 'false',
                    trim($e->getMessage())
                );
            }
        }
    }

    // SQL Authentication remains supported and is attempted after Windows auth.
    if (!$cfg['trusted_connection'] && ($cfg['password'] === null || $cfg['password'] === '')) {
        throw new RuntimeException(
            "SQL Server connection failed. Windows Authentication was attempted first but did not succeed. SQL authentication is also unavailable because no SQL password is configured.\n\n" . implode("\n", $errors)
        );
    }

    foreach ($profiles as $profile) {
        $dsn = artic_make_dsn($cfg, $profile['server'], $profile['encrypt'], $profile['trust']);
        try {
            $pdo = new PDO(
                $dsn,
                $cfg['username'],
                $cfg['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
                ]
            );
            $profile['auth'] = 'sql';
            return ['pdo' => $pdo, 'profile' => $profile];
        } catch (PDOException $e) {
            $errors[] = sprintf(
                '%s | encrypt=%s | auth=sql | %s',
                $profile['server'],
                $profile['encrypt'] ? 'true' : 'false',
                trim($e->getMessage())
            );
        }
    }

    throw new RuntimeException(
        "SQL Server connection failed. Windows Authentication and SQL Authentication were both attempted.\n\n" . implode("\n", $errors)
    );
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $result = artic_connect_with_config(artic_connection_config());
    $pdo = $result['pdo'];
    return $pdo;
}
