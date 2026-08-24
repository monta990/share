<?php
declare(strict_types=1);

function db(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (!extension_loaded('pdo_sqlite') || !extension_loaded('sqlite3')) {
        throw new RuntimeException('SQLite no está habilitado. Activa las extensiones PHP pdo_sqlite y sqlite3.');
    }

    $dir = dirname($config['database_path']);    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear el directorio de la base de datos.');
    }

    $pdo = new PDO('sqlite:' . $config['database_path'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    @chmod($config['database_path'], 0660);

    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('PRAGMA foreign_keys=ON');
    try { $pdo->exec('PRAGMA journal_mode=WAL'); } catch (Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL,
        last_login_at TEXT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        key_prefix TEXT NOT NULL,
        key_hash TEXT NOT NULL UNIQUE,
        created_at TEXT NOT NULL,
        last_used_at TEXT NULL,
        request_count INTEGER NOT NULL DEFAULT 0,
        revoked_at TEXT NULL,
        requests_per_hour INTEGER NOT NULL DEFAULT 0,
        scopes_json TEXT NOT NULL DEFAULT '[\"files.upload\"]',
        quota_files_per_day INTEGER NOT NULL DEFAULT 0,
        quota_bytes_per_day INTEGER NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS files (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        download_id TEXT NOT NULL UNIQUE,
        original_name TEXT NOT NULL,
        stored_name TEXT NOT NULL UNIQUE,
        mime_type TEXT NOT NULL,
        file_size INTEGER NOT NULL,
        sha256 TEXT NOT NULL,
        pin_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        created_at TEXT NOT NULL,
        downloads INTEGER NOT NULL DEFAULT 0,
        last_download_at TEXT NULL,
        max_downloads INTEGER NOT NULL DEFAULT 0,
        one_time INTEGER NOT NULL DEFAULT 0,
        api_key_id INTEGER NULL,
        antivirus_scanned INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE SET NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS upload_rate_limits (
        ip_address TEXT NOT NULL,
        window_start INTEGER NOT NULL,
        upload_count INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (ip_address, window_start)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS api_key_daily_usage (
        api_key_id INTEGER NOT NULL,
        usage_date TEXT NOT NULL,
        file_count INTEGER NOT NULL DEFAULT 0,
        byte_count INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (api_key_id, usage_date),
        FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_files_download_id ON files(download_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_files_expires_at ON files(expires_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_files_api_key_id ON files(api_key_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_files_sha256 ON files(sha256)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_files_created_at ON files(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_files_last_download_at ON files(last_download_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_upload_rate_limits_window ON upload_rate_limits(window_start)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_api_keys_active ON api_keys(revoked_at)");

    $defaults = [
        'duration_hours' => (string)(int)$config['expiration_hours'],
        'default_max_downloads' => '0',
        'default_one_time' => '0',
        'share_template_es' => default_share_template_for_language('es'),
        'share_template_en' => default_share_template_for_language('en'),
        'footer_template' => '{app_name}',
        'default_language' => (string)($config['default_language'] ?? 'en'),
        'timezone' => (string)($config['timezone'] ?? date_default_timezone_get()),
        'php_max_execution_time' => '300',
        'php_max_input_time' => '300',
        'last_cleanup_at' => '',
        'maintenance_mode' => '1',
        'cron_last_run_at' => '',
        'cron_last_run_epoch' => '0',
        'cron_last_status' => '',
        'cron_last_duration_ms' => '0',
        'cron_last_deleted' => '0',
        'cron_last_error' => '',
        'cron_last_source' => '',
        'timestamp_storage_timezone' => (string)($config['timezone'] ?? date_default_timezone_get()),
        'timestamp_storage_normalized' => '0',
    ];

    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings(key,value) VALUES(?,?)');
    foreach ($defaults as $key => $value) {
        $stmt->execute([$key, $value]);
    }

    // Low-cost query-planner maintenance on connection initialization.
    try { $pdo->exec('PRAGMA optimize'); } catch (Throwable $e) {}

    return $pdo;
}

function log_db(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (!extension_loaded('pdo_sqlite') || !extension_loaded('sqlite3')) {
        throw new RuntimeException('SQLite no está habilitado para el registro.');
    }

    $dir = dirname($config['logs_database_path']);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear el directorio del registro.');
    }

    $pdo = new PDO('sqlite:' . $config['logs_database_path'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    @chmod($config['logs_database_path'], 0660);

    $pdo->exec('PRAGMA busy_timeout=10000');
    $pdo->exec('PRAGMA foreign_keys=ON');
    try { $pdo->exec('PRAGMA journal_mode=WAL'); } catch (Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_meta(key TEXT PRIMARY KEY,value TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_time TEXT NOT NULL,
        app_version TEXT NOT NULL,
        request_id TEXT NOT NULL,
        severity TEXT NOT NULL,
        security_event INTEGER NOT NULL DEFAULT 1,
        event_type TEXT NOT NULL,
        outcome TEXT NOT NULL,
        actor_type TEXT NOT NULL,
        actor_id TEXT NULL,
        source_ip TEXT NULL,
        method TEXT NULL,
        path TEXT NULL,
        target_type TEXT NULL,
        target_id TEXT NULL,
        message TEXT NOT NULL,
        metadata_json TEXT NULL,
        prev_hash TEXT NOT NULL,
        event_hash TEXT NOT NULL UNIQUE
    )");
    foreach ([
        "CREATE INDEX IF NOT EXISTS idx_audit_logs_time ON audit_logs(event_time DESC)",
        "CREATE INDEX IF NOT EXISTS idx_audit_logs_event ON audit_logs(event_type)",
        "CREATE INDEX IF NOT EXISTS idx_audit_logs_severity ON audit_logs(severity)",
        "CREATE INDEX IF NOT EXISTS idx_audit_logs_security ON audit_logs(security_event)",
        "CREATE INDEX IF NOT EXISTS idx_audit_logs_request ON audit_logs(request_id)"
    ] as $sql) $pdo->exec($sql);
    $pdo->exec("INSERT OR IGNORE INTO audit_meta(key,value) VALUES('chain_anchor','" . str_repeat('0',64) . "')");

    return $pdo;
}


function optimize_sqlite_database(PDO $pdo, bool $vacuum = true): array
{
    $before = null;
    $after = null;
    $integrity = null;
    $started = microtime(true);

    try {
        $beforeRow = $pdo->query('PRAGMA page_count')->fetchColumn();
        $before = is_numeric($beforeRow) ? (int)$beforeRow : null;
    } catch (Throwable $e) {}

    // Recommended by SQLite for statistics/query planner maintenance.
    $pdo->exec('PRAGMA optimize');

    if ($vacuum) {
        // VACUUM rebuilds and compacts the database. SQLite requires no active
        // transaction and may need temporary disk space roughly comparable to
        // the database size.
        $pdo->exec('VACUUM');
    }

    try {
        $afterRow = $pdo->query('PRAGMA page_count')->fetchColumn();
        $after = is_numeric($afterRow) ? (int)$afterRow : null;
    } catch (Throwable $e) {}

    try {
        $integrity = (string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
    } catch (Throwable $e) {
        $integrity = null;
    }

    return [
        'ok' => ($integrity === null || strtolower($integrity) === 'ok'),
        'integrity' => $integrity,
        'page_count_before' => $before,
        'page_count_after' => $after,
        'duration_ms' => (int)round((microtime(true) - $started) * 1000),
    ];
}

function optimize_sqlite_databases(array $config): array
{
    $results = [];

    $main = db($config);
    $results['main'] = optimize_sqlite_database($main, true);

    $logs = log_db($config);
    $results['logs'] = optimize_sqlite_database($logs, true);

    return $results;
}
