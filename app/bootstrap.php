<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config/config.php';
ini_set('display_errors','0');
ini_set('log_errors','1');
date_default_timezone_set($config['timezone']);

function detect_app_cookie_path(): string
{
    $script = '/' . ltrim(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    foreach ([
        '#/api/(?:upload\.php|v1-upload\.php)$#',
        '#/(?:admin|404|500|download|index)\.php$#',
    ] as $pattern) {
        if (preg_match($pattern, $script)) {
            $base = preg_replace($pattern, '', $script, 1);
            $base = '/' . trim((string)$base, '/');
            return $base === '/' ? '/' : rtrim($base, '/') . '/';
        }
    }
    return '/';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['session_name']);
    session_start([
        'cookie_path' => detect_app_cookie_path(),
        'cookie_httponly' => true,
        'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true,
        'use_only_cookies' => true,
        'cookie_lifetime' => (int)($config['session_lifetime'] ?? 28800),
        'gc_maxlifetime' => (int)($config['session_lifetime'] ?? 28800),
    ]);
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/audit.php';

send_security_headers();
$GLOBALS['audit_request_id'] = null;

$bootstrapPhase = 'storage';
try {
    ensure_storage($config);

    $bootstrapPhase = 'database';
    $db = db($config);

    $bootstrapPhase = 'settings';
    $configuredTimezone = (string)setting($db, 'timezone', (string)$config['timezone']);
    if (in_array($configuredTimezone, DateTimeZone::listIdentifiers(), true)) {
        $config['timezone'] = $configuredTimezone;
        date_default_timezone_set($configuredTimezone);
    }

    // v1.0.99: normalize legacy file timestamps once to UTC so changing
    // the configured timezone only changes their displayed local time.
    if (setting($db, 'timestamp_storage_normalized', '0') !== '1') {
        $sourceTimezone = (string)setting($db, 'timestamp_storage_timezone', $configuredTimezone);
        if (!in_array($sourceTimezone, DateTimeZone::listIdentifiers(), true)) {
            $sourceTimezone = $configuredTimezone;
        }
        $rows = $db->query("SELECT id, expires_at, created_at, last_download_at FROM files")->fetchAll();
        $updates = [];
        $sourceTz = new DateTimeZone($sourceTimezone);
        foreach ($rows as $row) {
            $convert = static function (?string $value) use ($sourceTz): ?string {
                if ($value === null || $value === '') return $value;
                try {
                    return (new DateTimeImmutable($value, $sourceTz))
                        ->setTimezone(new DateTimeZone('UTC'))
                        ->format('Y-m-d H:i:s');
                } catch (Throwable $e) {
                    return $value;
                }
            };
            $updates[] = [
                'id'=>(int)$row['id'],
                'expires_at'=>$convert((string)$row['expires_at']),
                'created_at'=>$convert((string)$row['created_at']),
                'last_download_at'=>$convert($row['last_download_at']!==null?(string)$row['last_download_at']:null),
            ];
        }
        if ($updates) {
            $stmt=$db->prepare('UPDATE files SET expires_at=?, created_at=?, last_download_at=? WHERE id=?');
            foreach($updates as $u){ $stmt->execute([$u['expires_at'],$u['created_at'],$u['last_download_at'],$u['id']]); }
        }
        set_setting($db, 'timestamp_storage_timezone', 'UTC');
        set_setting($db, 'timestamp_storage_normalized', '1');
    }

    $bootstrapPhase = 'configuration';
    $config['max_file_size'] = platform_max_file_size_bytes($db, $config);
    audit_request_id();

    $bootstrapPhase = 'maintenance';
    $maintenancePath = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $maintenanceBypass = $maintenancePath === 'maintenance.php' || request_is_admin_area() || request_is_cron();
    if (maintenance_enabled($db) && !$maintenanceBypass) {
        render_maintenance_response($db, $config);
    }

    $bootstrapPhase = 'audit';
    audit_prune($db, $config);
} catch (Throwable $e) {
    // Full diagnostic stays in storage/, whose write access is already required.
    // Never expose SQL/path/stack details to the browser.
    try {
        $diag = [
            'time' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'phase' => $bootstrapPhase,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
        $diagPath = $config['storage_path'] . DIRECTORY_SEPARATOR . '.bootstrap-error.log';
        @file_put_contents(
            $diagPath,
            json_encode($diag, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        @chmod($diagPath, 0600);
    } catch (Throwable $ignored) {}
    render_environment_error($e, $config, $bootstrapPhase);
}
