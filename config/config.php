<?php
declare(strict_types=1);
return [
    'version' => '1.0.0',
    'app_name' => 'Portal de archivos',
    'app_tagline' => 'Comparte archivos de forma sencilla y temporal',
    // Empty = automatic URL detection. Set a URL only if a reverse proxy requires it.
    'base_url' => '',
    'timezone' => (function(){ $tz = date_default_timezone_get(); return in_array($tz, DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC'; })(),
    'default_language' => 'en',
    'expiration_hours' => 72,
    'max_file_size' => 1024 * 1024 * 1024,
    'antivirus_enabled' => false,
    'antivirus_command' => 'clamdscan --no-summary {file}',
    'minimum_free_space' => 512 * 1024 * 1024,
    'chunk_size' => 8 * 1024 * 1024,
    'storage_path' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage',
    'database_path' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'fileshare.sqlite',
    'session_name' => 'fileshare_session',
    'session_lifetime' => 28800,
    'log_retention_days' => 180,
    'log_max_rows' => 100000,
    'logs_database_path' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'logs.sqlite',
    'logs_retention_days' => 180,
    'logs_max_events' => 100000,
    'upload_rate_limit_per_hour' => 20,
    'upload_rate_limit_window_seconds' => 3600,
];
