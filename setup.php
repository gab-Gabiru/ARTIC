<?php

declare(strict_types=1);

/**
 * Artic local installer / repair / connection configurator.
 *
 * This page is intentionally local-only. It can:
 *   1) test the SQL Server connection supplied in the form,
 *   2) save config.local.php,
 *   3) create/repair the Artic schema and seed data.
 */

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1', 'localhost', '::ffff:127.0.0.1'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Artic setup is available only from localhost.";
    exit;
}

require_once __DIR__ . '/db.php';

function setup_defaults(): array
{
    $cfg = artic_local_config();
    return [
        'server' => (string)($cfg['server'] ?? 'localhost\\SQLEXPRESS03'),
        'port' => (string)($cfg['port'] ?? ''),
        'database' => (string)($cfg['database'] ?? 'artic'),
        'username' => (string)($cfg['username'] ?? 'sa'),
        'password' => (string)($cfg['password'] ?? ''),
        'trust_server_certificate' => isset($cfg['trust_server_certificate']) ? (bool)$cfg['trust_server_certificate'] : true,
        'trusted_connection' => isset($cfg['trusted_connection']) ? (bool)$cfg['trusted_connection'] : false,
        'encrypt' => isset($cfg['encrypt']) ? (bool)$cfg['encrypt'] : true,
        'login_timeout' => isset($cfg['login_timeout']) ? max(1, (int)$cfg['login_timeout']) : 8,
        'allow_windows_fallback' => isset($cfg['allow_windows_fallback']) ? (bool)$cfg['allow_windows_fallback'] : true,
    ];
}

function posted_config(): array
{
    $defaults = setup_defaults();
    return [
        'server' => trim((string)($_POST['server'] ?? $defaults['server'])),
        'port' => trim((string)($_POST['port'] ?? $defaults['port'])),
        'database' => trim((string)($_POST['database'] ?? $defaults['database'])),
        'username' => trim((string)($_POST['username'] ?? $defaults['username'])),
        'password' => (string)($_POST['password'] ?? $defaults['password']),
        'trust_server_certificate' => isset($_POST['trust_server_certificate']) && $_POST['trust_server_certificate'] === '1',
        'trusted_connection' => isset($_POST['trusted_connection']) && $_POST['trusted_connection'] === '1',
        'encrypt' => !isset($_POST['encrypt']) || $_POST['encrypt'] === '1',
        'login_timeout' => max(1, min(60, (int)($_POST['login_timeout'] ?? $defaults['login_timeout']))),
        'allow_windows_fallback' => isset($_POST['allow_windows_fallback']) && $_POST['allow_windows_fallback'] === '1',
    ];
}

function validate_setup_config(array $cfg): void
{
    if ($cfg['server'] === '') {
        throw new RuntimeException('SQL Server instance is required. Example: localhost\\SQLEXPRESS03');
    }
    if ($cfg['port'] !== '' && !preg_match('/^\\d{1,5}$/', (string)$cfg['port'])) {
        throw new RuntimeException('SQL Server TCP port must be numeric, for example 1433.');
    }
    if ($cfg['database'] === '') {
        throw new RuntimeException('Database name is required.');
    }
    if (!preg_match('/^[A-Za-z0-9_$-]+$/', $cfg['database'])) {
        throw new RuntimeException('Database name contains unsupported characters.');
    }
    if (!$cfg['trusted_connection'] && $cfg['username'] === '') {
        throw new RuntimeException('SQL username is required when Windows Authentication is disabled.');
    }
    if (!$cfg['trusted_connection'] && $cfg['password'] === '') {
        throw new RuntimeException('SQL password is required when Windows Authentication is disabled.');
    }
}

/** @return array{pdo:PDO,profile:array{server:string,encrypt:bool,trust:bool,auth:string}} */
function setup_connect_database(array $cfg, string $database): array
{
    $runtimeCfg = $cfg;
    $runtimeCfg['database'] = $database;
    return artic_connect_with_config($runtimeCfg);
}

function write_local_config(array $cfg): void
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
    $contents = "<?php\nreturn " . var_export($cfg, true) . ";\n";
    if (@file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException(
            'Could not write config.local.php. Give the project folder write permission, or create config.local.php manually from config.local.example.php.'
        );
    }
}

function run_sql_file(PDO $pdo, string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('SQL file not found: ' . basename($path));
    }

    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Unable to read ' . basename($path));
    }

    $batches = preg_split('/^\s*GO\s*;?\s*$/mi', $sql) ?: [];
    $executed = 0;

    foreach ($batches as $batch) {
        $batch = trim($batch);
        if ($batch === '') {
            continue;
        }

        // Skip informational SELECT-only batches from the schema file.
        if (preg_match('/^SELECT\b/i', $batch)) {
            continue;
        }

        $pdo->exec($batch);
        $executed++;
    }

    return ['file' => basename($path), 'batches' => $executed];
}

$cfg = setup_defaults();
$messages = [];
$error = null;
$action = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'install');
    $cfg = posted_config();

    try {
        if ($action === 'test') {
            [$pdo, $profile] = setup_connect_database($cfg, 'master');
            $server = (string)$pdo->query('SELECT @@SERVERNAME')->fetchColumn();
            $version = (string)$pdo->query('SELECT CAST(SERVERPROPERTY(\'ProductVersion\') AS NVARCHAR(100))')->fetchColumn();
            $messages[] = [
                'connection' => 'ok',
                'server' => $server,
                'version' => $version,
                'connection_profile' => $profile,
            ];
        } elseif ($action === 'save' || $action === 'install') {
            // Use master while installing because the artic database may not exist yet.
            [$master, $profile] = setup_connect_database($cfg, 'master');
            $messages[] = [
                'connection' => 'ok',
                'connection_profile' => $profile,
            ];

            if (isset($_POST['save_config']) || $action === 'install') {
                write_local_config($cfg);
                $messages[] = ['config' => 'saved', 'file' => 'config.local.php'];
            }

            if ($action === 'install') {
                $messages[] = run_sql_file($master, __DIR__ . '/schema.sql');
                $messages[] = run_sql_file($master, __DIR__ . '/REPAIR_EXISTING_ARTIC_DB.sql');

                // Verify with a fresh application connection using the saved config.
                [$pdo, $appProfile] = setup_connect_database($cfg, (string)$cfg['database']);
                $dbName = (string)$pdo->query('SELECT DB_NAME()')->fetchColumn();
                $tableCount = (int)$pdo->query(
                    "SELECT COUNT(*) FROM sys.tables WHERE schema_id = SCHEMA_ID(N'dbo')"
                )->fetchColumn();

                $messages[] = [
                    'database' => $dbName,
                    'dbo_table_count' => $tableCount,
                    'verification' => 'passed',
                    'connection_profile' => $appProfile,
                ];
            }
        } else {
            throw new RuntimeException('Unknown setup action.');
        }
    } catch (Throwable $e) {
        error_log($e->getMessage());
        $error = $e->getMessage();
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Artic Local Setup</title>
<style>
body{font-family:Arial,sans-serif;background:#0b0f17;color:#f1f5f9;margin:0;padding:32px}
.card{max-width:820px;margin:auto;background:#131a26;border:1px solid #2e405e;border-radius:14px;padding:28px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.full{grid-column:1/-1}
label{display:block;font-size:13px;font-weight:700;margin-bottom:6px}input{width:100%;box-sizing:border-box;background:#0c1220;color:#f1f5f9;border:1px solid #344766;border-radius:8px;padding:11px}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:16px}
button{background:#06b6d4;color:#fff;border:0;border-radius:7px;padding:11px 16px;font-weight:700;cursor:pointer}.secondary{background:#29364d}
.note{font-size:13px;color:#a8b7cc;line-height:1.5}.ok{background:#10261f;border:1px solid #10b981;padding:14px;border-radius:8px;margin-bottom:16px}.err{background:#2a1518;border:1px solid #ef4444;padding:14px;border-radius:8px;margin-bottom:16px;white-space:pre-wrap}.warn{background:#211d0c;border:1px solid #eab308;padding:12px;border-radius:8px;margin-bottom:16px}
code{color:#67e8f9}.check{display:flex;gap:8px;align-items:center;font-size:13px}.check input{width:auto}
@media(max-width:700px){.grid{grid-template-columns:1fr}.full{grid-column:auto}}
</style>
</head>
<body>
<div class="card">
  <h1>Artic SQL Server Setup</h1>
  <p class="note">Use this page from <code>http://localhost/...</code>. It tests the actual MSSQL connection, tries local named-instance aliases when appropriate, saves the working configuration for localhost, and can install/repair the full database schema.</p>

  <?php if (!extension_loaded('pdo_sqlsrv')): ?>
    <div class="warn"><strong>PDO_SQLSRV is not loaded.</strong><br>This PHP runtime cannot talk to SQL Server until the Microsoft SQL Server PDO driver is installed/enabled. The page itself is working; only the database driver is missing.</div>
  <?php endif; ?>

  <?php if ($messages): ?>
    <div class="ok">
      <strong>Success</strong>
      <pre><?= h(json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
      <p><a href="index.php" style="color:#67e8f9">Open Artic</a> · <a href="health.php" style="color:#67e8f9">Open health check</a></p>
    </div>
  <?php endif; ?>

  <?php if ($error !== null): ?>
    <div class="err"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <div class="grid">
      <div class="full">
        <label>SQL Server instance</label>
        <input name="server" value="<?= h($cfg['server']) ?>" placeholder="localhost\\SQLEXPRESS03" required>
      </div>
      <div>
        <label>Database</label>
        <input name="database" value="<?= h($cfg['database']) ?>" placeholder="artic" required>
      </div>
      <div>
        <label>Username</label>
        <input name="username" value="<?= h($cfg['username']) ?>" placeholder="sa">
      </div>
      <div class="full">
        <label>Password</label>
        <input type="password" name="password" value="<?= h($cfg['password']) ?>" autocomplete="new-password" placeholder="SQL Server password">
      </div>
      <div class="full check"><input type="checkbox" name="trusted_connection" value="1" <?= $cfg['trusted_connection'] ? 'checked' : '' ?>> Use Windows Authentication (Trusted Connection)</div>
      <div class="full check"><input type="checkbox" name="allow_windows_fallback" value="1" <?= !empty($cfg['allow_windows_fallback']) ? 'checked' : '' ?>> If <code>sa</code> login fails, try Windows Authentication automatically on local instances</div>
      <div class="full check"><input type="checkbox" name="trust_server_certificate" value="1" <?= $cfg['trust_server_certificate'] ? 'checked' : '' ?>> Trust local SQL Server certificate</div>
      <div class="full check"><input type="checkbox" name="encrypt" value="1" <?= $cfg['encrypt'] ? 'checked' : '' ?>> Prefer encrypted SQL Server connection</div>
    </div>

    <div class="row">
      <button class="secondary" type="submit" name="action" value="test">Test Connection</button>
      <button class="secondary" type="submit" name="action" value="save">Save Configuration</button>
      <button type="submit" name="action" value="install">Save + Install / Repair Database</button>
    </div>
  </form>

  <p class="note" style="margin-top:18px">For local SQL Server Express, start with <code>localhost\SQLEXPRESS03</code> / <code>artic</code> / <code>sa</code>. If your instance uses a different name or fixed TCP port, enter it above. The connection result shows the exact server/profile that succeeded.</p>
</div>
</body>
</html>
