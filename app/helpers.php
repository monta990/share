<?php
declare(strict_types=1);

function maintenance_enabled(PDO $db): bool
{
    return setting($db, 'maintenance_mode', '0') === '1';
}

function request_is_admin_area(): bool
{
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $uri = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
    return $script === 'admin.php' || preg_match('#/(?:admin)(?:/|$)#', (string)$uri) === 1;
}

function request_is_api(): bool
{
    $script = '/' . ltrim(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $uri = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
    return preg_match('#/api/(?:[^/]+)$#', $script) === 1 || preg_match('#/api(?:/|$)#', (string)$uri) === 1;
}

function request_is_cron(): bool
{
    return PHP_SAPI === 'cli' || basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'cleanup.php';
}

function render_maintenance_response(PDO $db, array $config): never
{
    $lang = function_exists('resolve_language') ? resolve_language($db, $config, null) : 'en';
    $branding = function_exists('branding') ? branding($db, $config) : ['name'=>'Portal de archivos','logo'=>'','tagline'=>''];
    $isApi = request_is_api();

    if ($isApi) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Retry-After: 3600');
        echo json_encode([
            'ok' => false,
            'error' => 'maintenance_mode',
            'code' => 'MAINTENANCE_MODE',
            'message' => $lang === 'es'
                ? 'La plataforma está temporalmente en mantenimiento. Intenta nuevamente más tarde.'
                : 'The platform is temporarily under maintenance. Please try again later.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(503);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Retry-After: 3600');

    $title = $lang === 'es' ? 'Servicio temporalmente no disponible' : 'Service temporarily unavailable';
    $lead = $lang === 'es' ? 'La aplicación se encuentra en mantenimiento.' : 'The application is currently under maintenance.';
    $message = $lang === 'es'
        ? 'Estamos realizando tareas de mantenimiento. Intenta nuevamente más tarde.'
        : 'We are performing maintenance. Please try again later.';
    $requirements = $lang === 'es' ? 'Estado' : 'Status';
    $status = $lang === 'es'
        ? 'El acceso público está temporalmente pausado.'
        : 'Public access is temporarily paused.';
    $label = $lang === 'es' ? 'Modo mantenimiento' : 'Maintenance mode';
    ?>
    <!doctype html>
    <html lang="<?=e($lang)?>">
    <head>
      <meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title><?=e($title)?> · <?=e($branding['name'])?></title>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
      <link rel="stylesheet" href="<?=e(app_url($config,'assets/app.css'))?>">
    </head>
    <body>
      <nav class="navbar border-bottom">
        <div class="container-fluid px-4 px-xxl-5 py-2">
          <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold" href="<?=e(app_base_url($config))?>/">
            <?php if(!empty($branding['logo'])): ?>
              <img src="<?=e(app_url($config,$branding['logo']))?>" class="brand-logo" alt="Logo">
            <?php else: ?>
              <span class="brand-icon"><i class="bi bi-cloud-arrow-up-fill"></i></span>
            <?php endif; ?>
            <span><?=e($branding['name'])?></span>
          </a>
          <div class="dropdown">
            <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-circle-half me-1" aria-hidden="true"></i><?= $lang === 'es' ? 'Tema' : 'Theme' ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><button type="button" class="dropdown-item theme-choice" data-theme="light"><i class="bi bi-sun me-2" aria-hidden="true"></i><?= $lang === 'es' ? 'Claro' : 'Light' ?></button></li>
              <li><button type="button" class="dropdown-item theme-choice" data-theme="dark"><i class="bi bi-moon-stars me-2" aria-hidden="true"></i><?= $lang === 'es' ? 'Oscuro' : 'Dark' ?></button></li>
              <li><button type="button" class="dropdown-item theme-choice" data-theme="auto"><i class="bi bi-circle-half me-2" aria-hidden="true"></i><?= $lang === 'es' ? 'Automático' : 'Automatic' ?></button></li>
            </ul>
          </div>
        </div>
      </nav>
      <main class="container py-5">
        <div class="row justify-content-center">
          <div class="col-md-10 col-lg-8">
            <div class="card border-0 shadow-sm p-4 p-md-5 text-center">
              <div class="hero-icon mx-auto mb-3"><i class="bi bi-tools"></i></div>
              <h1 class="h2 mb-2"><?=e($title)?></h1>
              <p class="h5 text-body-secondary mb-3"><?=e($lead)?></p>
              <div class="alert alert-warning text-start mb-0">
                <h2 class="h5 mb-3"><?=e($label)?></h2>
                <ul class="mb-0">
                  <li><?=e($message)?></li>
                  <li><?=e($requirements)?>: <?=e($status)?></li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </main>
      <footer class="text-center text-body-secondary small py-4"><?=e($branding['name'])?></footer>
      <script src="<?=e(app_url($config,'assets/theme.js'))?>"></script>
      <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    </body>
    </html>
    <?php
    exit;
}


function platform_max_file_size_mb(PDO $db, array $config): int
{
    return max(1, (int)floor(platform_max_file_size_bytes($db, $config) / 1048576));
}

function platform_max_file_size_bytes(PDO $db, array $config): int
{
    $defaultMb = max(1, (int)floor(((int)($config['max_file_size'] ?? (1024 * 1024 * 1024))) / 1048576));
    $mb = (int)setting($db, 'max_file_size_mb', (string)$defaultMb);
    $mb = max(1, min(1048576, $mb));
    return $mb * 1048576;
}

function php_ini_size_for_mb(int $mb): string
{
    $mb = max(1, $mb);
    return ($mb >= 1024 && $mb % 1024 === 0) ? ((int)($mb / 1024) . 'G') : ($mb . 'M');
}

function sync_php_upload_ini(array $config, int $maxMb): void
{
    $maxMb = max(1, min(1048576, $maxMb));
    $upload = php_ini_size_for_mb($maxMb);
    $postMb = min(1048576, $maxMb + 16);
    $post = php_ini_size_for_mb($postMb);
    $execution = max(30, min(3600, (int)($config['php_max_execution_time'] ?? 300)));
    $inputTime = max(30, min(3600, (int)($config['php_max_input_time'] ?? 300)));
    $timezone = (string)($config['timezone'] ?? date_default_timezone_get());
    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        $timezone = date_default_timezone_get();
    }
    $publicDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public';
    $content = "; Generated by Portal de archivos.\n"
        . "; Regenerated from Administración when upload limit, PHP runtime limits, or timezone changes.\n"
        . "file_uploads = On\n"
        . "upload_max_filesize = {$upload}\n"
        . "post_max_size = {$post}\n"
        . "max_file_uploads = 50\n"
        . "max_input_vars = 3000\n"
        . "max_execution_time = {$execution}\n"
        . "max_input_time = {$inputTime}\n"
        . "date.timezone = {$timezone}\n"
        . "display_errors = 0\n"
        . "log_errors = 1\n"
        . "expose_php = 0\n";
    foreach (['php.ini', '.user.ini'] as $filename) {
        $path = $publicDir . DIRECTORY_SEPARATOR . $filename;
        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException("No se pudo actualizar public/{$filename}. Verifica permisos de escritura.");
        }
        @chmod($path, 0640);
    }
}

function cron_heartbeat(PDO $db, string $status, int $durationMs = 0, int $deleted = 0, string $error = '', string $source = 'scheduled'): void
{
    global $config;
    $now=new DateTimeImmutable('now');
    $payload=[
        'last_run_at'=>$now->format(DateTimeInterface::ATOM),
        'last_run_epoch'=>time(),
        'status'=>$status,
        'duration_ms'=>max(0,$durationMs),
        'deleted'=>max(0,$deleted),
        'error'=>$error,
        'source'=>$source,
        'app_version'=>(string)($config['version']??''),
    ];

    $path=dirname($config['database_path']).DIRECTORY_SEPARATOR.'cron-heartbeat.json';
    $tmp=$path.'.tmp-'.bin2hex(random_bytes(4));
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($json!==false && @file_put_contents($tmp,$json,LOCK_EX)!==false){
        @chmod($tmp,0660); @rename($tmp,$path); @chmod($path,0660);
    }

    try{
        set_setting($db,'cron_last_run_at',$payload['last_run_at']);
        set_setting($db,'cron_last_run_epoch',(string)$payload['last_run_epoch']);
        set_setting($db,'cron_last_status',$payload['status']);
        set_setting($db,'cron_last_duration_ms',(string)$payload['duration_ms']);
        set_setting($db,'cron_last_deleted',(string)$payload['deleted']);
        set_setting($db,'cron_last_error',$payload['error']);
        set_setting($db,'cron_last_source',$payload['source']);
        set_setting($db,'last_cleanup_at',$payload['last_run_at']);
    }catch(Throwable $e){}
}

function run_cleanup_job(PDO $db, array $config, string $actor = 'system', string $source = 'scheduled'): array
{
    $started = microtime(true);
    $count = 0;
    $errors = [];

    foreach ($db->query("SELECT * FROM files WHERE expires_at<=datetime('now')")->fetchAll() as $file) {
        try {
            delete_file_record($config, $db, $file);
            $count++;
            audit_event($db,$config,'file_expired_delete','INFO',true,'success',$actor,null,
                $source==='manual'?'Archivo expirado eliminado mediante limpieza manual.':'Archivo expirado eliminado por limpieza automática.',
                [],'file',(string)$file['id']);
        } catch(Throwable $e) {
            $errors[]='archivo '.$file['id'].': '.$e->getMessage();
            audit_event($db,$config,'file_expired_delete','ERROR',true,'error',$actor,null,
                'No se pudo eliminar un archivo expirado.',[],'file',(string)$file['id']);
        }
    }

    foreach (glob($config['storage_path'].DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'*.part') ?: [] as $tmp) {
        if (is_file($tmp) && @filemtime($tmp) < time()-21600) @unlink($tmp);
    }

    cleanup_upload_rate_limits($db);

    $durationMs=(int)round((microtime(true)-$started)*1000);
    $status=$errors?'error':'success';
    cron_heartbeat($db,$status,$durationMs,$count,implode(' | ',array_slice($errors,0,5)),$source);

    audit_event($db,$config,'system_cleanup',$errors?'WARNING':'INFO',true,$errors?'failure':'success',$actor,null,
        $source==='manual'
            ?($errors?'Limpieza manual terminó con errores.':'Limpieza manual ejecutada correctamente.')
            :($errors?'Limpieza programada terminó con errores.':'Limpieza programada ejecutada correctamente.'),
        ['deleted'=>$count,'duration_ms'=>$durationMs,'errors'=>count($errors),'source'=>$source]);

    return ['status'=>$status,'deleted'=>$count,'errors'=>$errors,'duration_ms'=>$durationMs,'source'=>$source];
}

function ensure_storage(array $config): void
{
    $dirs = [
        'storage' => (string)$config['storage_path'],
        'files' => (string)$config['storage_path'] . DIRECTORY_SEPARATOR . 'files',
        'tmp' => (string)$config['storage_path'] . DIRECTORY_SEPARATOR . 'tmp',
        'db' => dirname((string)$config['database_path']),
    ];

    foreach ($dirs as $label => $dir) {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, $label === 'db' ? 0770 : 0770, true) && !is_dir($dir)) {
                throw new RuntimeException("No se pudo crear {$label}.");
            }
        }

        // storage is known to work with 770 on the target shared host.
        // Do not rely on is_writable() for db/ because ACL/FPM policies can
        // make it report a false negative; SQLite itself is the authoritative
        // write test for the database directory/files.
        if ($label !== 'db') {
            @chmod($dir, 0770);
            if (!is_writable($dir)) {
                throw new RuntimeException("La carpeta {$label} no es escribible para PHP.");
            }
            $probe = $dir . DIRECTORY_SEPARATOR . '.write-test-' . bin2hex(random_bytes(6));
            $fh = @fopen($probe, 'xb');
            if ($fh === false) {
                throw new RuntimeException("PHP no puede crear archivos en {$label}.");
            }
            fclose($fh);
            @unlink($probe);
        }
    }
}

function cron_heartbeat_snapshot(array $config): array
{
    $path=dirname($config['database_path']).DIRECTORY_SEPARATOR.'cron-heartbeat.json';
    if(!is_file($path)) return [];
    $raw=@file_get_contents($path);
    if($raw===false || trim($raw)==='') return [];
    $data=json_decode($raw,true);
    return is_array($data)?$data:[];
}

function ensure_available_space(array $config, int $bytes): void {
    $minimum = max(0, (int)($config['minimum_free_space'] ?? 0));
    $free = @disk_free_space($config['storage_path']);
    if ($free === false) return;
    if ($free < $bytes + $minimum) {
        throw new RuntimeException('No hay suficiente espacio libre para almacenar el archivo de forma segura.');
    }
}

function detect_mime_type(string $path): string {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($path);
    return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
}


function send_security_headers(): void {
    if (headers_sent()) {
        return;
    }

    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(18));
        $GLOBALS['csp_nonce'] = $nonce;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');

    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "base-uri 'self'; " .
        "form-action 'self'; " .
        "frame-ancestors 'none'; " .
        "object-src 'none'; " .
        "img-src 'self' data:; " .
        "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; " .
        "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net; " .
        "font-src 'self' https://cdn.jsdelivr.net; " .
        "connect-src 'self';"
    );

    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, noai, noimageai', true);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function csp_nonce(): string {
    return (string)($GLOBALS['csp_nonce'] ?? '');
}

function e(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Builds the public application URL from the current request.
 * This intentionally does not depend on a fixed domain or subdomain.
 * Works at the domain root and in a subdirectory, e.g. /fileshare/.
 */
function app_base_url(array $config): string {
    if (!empty($config['base_url'])) return rtrim((string)$config['base_url'], '/');
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $https ? 'https' : 'http';
    $host = trim((string)($_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    if (!preg_match('/^(?:\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)(?::\d{1,5})?$/', $host)) $host='localhost';
    $script='/' . ltrim(str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME'] ?? '')),'/');
    $basePath=null;
    foreach ([ '#/api/(?:upload\.php|v1-upload\.php)$#', '#/(?:admin|404|500|download|index)\.php$#' ] as $pattern) {
        if (preg_match($pattern,$script)) { $basePath=preg_replace($pattern,'',$script,1); break; }
    }
    if ($basePath===null) {
        $path='/' . ltrim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH) ?: '/'),'/');
        foreach ([ '#/f/[A-Za-z0-9_-]+/?$#','#/admin(?:/.*)?/?$#','#/api/v1/upload/?$#','#/api/upload\.php/?$#','#/download\.php/?$#','#/index\.php/?$#' ] as $pattern) {
            $stripped=preg_replace($pattern,'',$path,1);
            if ($stripped!==null && $stripped!==$path) { $path=$stripped ?: '/'; break; }
        }
        $basePath=$path;
    }
    $basePath='/' . trim((string)$basePath,'/');
    if ($basePath==='/') $basePath='';
    return $scheme.'://'.$host.$basePath;
}
function app_url(array $config, string $path = ''): string {
    $base = app_base_url($config);
    return $base . '/' . ltrim($path, '/');
}

/**
 * Genera URLs de assets con invalidación automática de caché.
 * Usa versión de aplicación + mtime físico del archivo cuando existe.
 */
function app_asset_url(array $config, string $path = ''): string {
    $url = app_url($config, $path);
    $parts = parse_url($path);
    $relative = ltrim((string)($parts['path'] ?? $path), '/');
    $assetPath = __DIR__ . '/../public/' . $relative;
    $version = (string)($config['version'] ?? '0');
    if (is_file($assetPath)) {
        $mtime = @filemtime($assetPath);
        if ($mtime !== false) $version .= '-' . $mtime;
    }
    return $url . '?v=' . rawurlencode($version);
}

function send_no_cache_headers(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function json_response(array $data, int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function verify_csrf(): void {
    $token=$_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$token)) json_response(['ok'=>false,'message'=>'Solicitud no válida.'],419);
}
function clean_original_name(string $name): string {
    return mb_substr(trim(str_replace(["\0","\r","\n"],'', $name)),0,255);
}
function random_download_id(): string {
    return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
}
function generate_pin(): string {
    return str_pad((string)random_int(0,9999),4,'0',STR_PAD_LEFT);
}
function utc_storage_datetime(DateTimeInterface $date): string {
    return (new DateTimeImmutable($date->format('Y-m-d H:i:s'), $date->getTimezone()))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
}

function stored_utc_datetime(string $date): DateTimeImmutable {
    return new DateTimeImmutable($date, new DateTimeZone('UTC'));
}

function local_datetime_for_config(DateTimeInterface $date, ?array $config = null): DateTimeImmutable {
    $config = $config ?? ($GLOBALS['config'] ?? []);
    $timezone = (string)($config['timezone'] ?? date_default_timezone_get());
    try {
        $tz = new DateTimeZone($timezone);
    } catch (Throwable $e) {
        $tz = new DateTimeZone(date_default_timezone_get());
    }
    return (new DateTimeImmutable($date->format('Y-m-d H:i:s'), new DateTimeZone('UTC')))
        ->setTimezone($tz);
}

function format_date(string $date): string {
    try { return local_datetime_for_config(stored_utc_datetime($date))->format('d/m/Y H:i'); }
    catch (Throwable $e) { return $date; }
}

function format_date_iso(string $date): string {
    try { return stored_utc_datetime($date)->format(DateTimeInterface::ATOM); }
    catch (Throwable $e) { return $date; }
}

function format_local_datetime(DateTimeInterface $date, ?array $config = null): string {
    return local_datetime_for_config($date, $config)->format('d/m/Y H:i');
}

function format_bytes(int $bytes): string {
    if ($bytes<1024) return $bytes.' B';
    $n=$bytes;
    foreach(['KB','MB','GB','TB'] as $u){$n/=1024;if($n<1024)return number_format($n,1).' '.$u;}
    return number_format($n,1).' PB';
}
function is_expired(array $file): bool { try { return stored_utc_datetime((string)$file['expires_at'])->getTimestamp() <= time(); } catch (Throwable $e) { return true; } }
function safe_file_path(array $config,string $stored): string {
    if (!preg_match('/^[a-f0-9]{64}$/',$stored)) throw new RuntimeException('Archivo inválido.');
    return $config['storage_path'].DIRECTORY_SEPARATOR.'files'.DIRECTORY_SEPARATOR.$stored;
}
function delete_file_record(array $config,PDO $db,array $file): void {
    $path=safe_file_path($config,$file['stored_name']);
    if (is_file($path) && !@unlink($path)) {
        throw new RuntimeException('No se pudo eliminar el archivo físico.');
    }
    $s=$db->prepare('DELETE FROM files WHERE id=?');
    $s->execute([$file['id']]);
}
function find_file_by_download_id(PDO $db,string $id): ?array {
    $s=$db->prepare('SELECT * FROM files WHERE download_id=? LIMIT 1');$s->execute([$id]);$r=$s->fetch();
    return $r?:null;
}

function cleanup_session_uploads(array $config): int
{
    $removed = 0;
    if (empty($_SESSION['uploads']) || !is_array($_SESSION['uploads'])) {
        $_SESSION['uploads'] = [];
        return 0;
    }

    $now = time();
    foreach ($_SESSION['uploads'] as $id => $meta) {
        $tmp = is_array($meta) ? (string)($meta['tmp'] ?? '') : '';
        $created = is_array($meta) ? (int)($meta['created'] ?? 0) : 0;

        $validId = is_string($id) && preg_match('/^[a-f0-9]{48}$/', $id);
        $validTmp = $tmp !== '' && is_file($tmp);
        $stale = ($created <= 0 || ($now - $created) > 21600);

        if (!$validId || !$validTmp || $stale) {
            if ($validTmp) {
                @unlink($tmp);
            }
            unset($_SESSION['uploads'][$id]);
            $removed++;
        }
    }

    return $removed;
}

function active_session_uploads(array $config): int
{
    cleanup_session_uploads($config);
    return is_array($_SESSION['uploads'] ?? null) ? count($_SESSION['uploads']) : 0;
}

function require_post(): void {
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') json_response(['ok'=>false,'message'=>'Método no permitido.'],405);
}


function default_share_template(): string {
    return "{app_name}

Archivo: {filename}

Descarga:
{url}

PIN:
{pin}

SHA-256:
{sha256}

El archivo estará disponible durante {duration}.
Expira: {expires_at} ({expires_at_iso}).
Duración: {duration_hours} horas";
}

function render_share_template(PDO $db, string $template, array $data): string {
    $allowed = [
        '{app_name}' => (string)setting($db, 'app_name', $GLOBALS['config']['app_name'] ?? 'Portal de archivos'),
        '{filename}' => (string)($data['filename'] ?? ''),
        '{url}' => (string)($data['url'] ?? ''),
        '{pin}' => (string)($data['pin'] ?? ''),
        '{sha256}' => (string)($data['sha256'] ?? ''),
        '{expires_at}' => (string)($data['expires_at'] ?? ''),
        '{expires_at_iso}' => (string)($data['expires_at_iso'] ?? ''),
        '{duration}' => (string)($data['duration'] ?? ''),
        '{duration_hours}' => (string)($data['duration_hours'] ?? ''),
    ];
    return strtr($template, $allowed);
}

function setting(PDO $db, string $key, ?string $default=null): ?string {
    $s=$db->prepare('SELECT value FROM settings WHERE key=? LIMIT 1'); $s->execute([$key]);
    $r=$s->fetchColumn(); return $r===false?$default:(string)$r;
}
function set_setting(PDO $db,string $key,string $value): void {
    $s=$db->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value'); $s->execute([$key,$value]);
}
function admin_count(PDO $db): int { return (int)$db->query('SELECT COUNT(*) FROM admins')->fetchColumn(); }
function admin_logged_in(): bool { return !empty($_SESSION['admin_id']); }
function require_admin_page(PDO $db): void {
    if (!admin_logged_in()) {
        audit_event($db, $GLOBALS['config'], 'authz_admin_failure', 'WARNING', true, 'failure', 'anonymous', null, 'Acceso no autorizado al área administrativa.');
        header('Location: ' . app_url($GLOBALS['config'], 'admin?login=1'), true, 303);
        exit;
    }
}
function admin_csrf_token(): string {
    if(empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf']=bin2hex(random_bytes(32));
    return $_SESSION['admin_csrf'];
}
function verify_admin_csrf(): void {
    $token=(string)($_POST['csrf']??'');
    if(!hash_equals($_SESSION['admin_csrf']??'', $token)){ if(isset($GLOBALS['db'],$GLOBALS['config'])) audit_event($GLOBALS['db'],$GLOBALS['config'],'csrf_admin_failure','WARNING',true,'failure','anonymous',null,'Token CSRF administrativo inválido.'); http_response_code(419);exit('Solicitud no válida.');}
}
function branding(PDO $db,array $config): array {
    $logo=(string)setting($db,'logo_path','');
    $logoVersion=(string)setting($db,'logo_version','');
    if($logo!==''){
        $diskPath=dirname(__DIR__).DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $logo);
        if($logoVersion===''){
            $mtime=@filemtime($diskPath);
            $logoVersion=$mtime!==false?(string)$mtime:bin2hex(random_bytes(8));
        }
        // The file itself keeps a stable physical name for simple deployment,
        // while the query string makes every replacement a new browser resource.
        $logo.='?v='.rawurlencode($logoVersion);
    }
    return [
        'name'=>setting($db,'app_name',$config['app_name']),
        'tagline'=>setting($db,'app_tagline',$config['app_tagline']),
        'logo'=>$logo,
    ];
}


function share_template_tags(): array
{
    return [
        '{app_name}',
        '{filename}',
        '{url}',
        '{pin}',
        '{sha256}',
        '{expires_at}',
        '{expires_at_iso}',
        '{duration}',
        '{duration_hours}',
    ];
}

function validate_share_template(string $template): array
{
    $allowed = share_template_tags();
    preg_match_all('/\{[a-z0-9_]+\}/', $template, $matches);

    $used = array_values(array_unique($matches[0] ?? []));
    $unknown = array_values(array_diff($used, $allowed));
    $missing = array_values(array_diff($allowed, $used));

    return [
        'ok' => !$unknown && !$missing,
        'unknown' => $unknown,
        'missing' => $missing,
    ];
}


function get_footer_template(PDO $db, array $config, bool $admin = false): string
{
    $default = '{app_name}';
    $template = trim((string)setting($db, 'footer_template', $default));
    if ($template === '') {
        $template = $default;
    }

    // The public footer only supports {app_name}.
    $template = strtr($template, [
        '{app_name}' => (string)setting($db, 'app_name', $config['app_name']),
    ]);

    // Administration uses the exact same public template, then appends
    // the platform version as fixed system information.
    if ($admin) {
        $version = trim((string)($config['version'] ?? ''));
        if ($version !== '') {
            $template = rtrim($template) . ' · v' . $version;
        }
    }

    return trim($template);
}

function footer_template_tags(bool $admin = false): array
{
    return ['{app_name}'];
}

function validate_footer_template(string $template): array
{
    preg_match_all('/\{[a-z0-9_]+\}/', $template, $matches);
    $used = array_values(array_unique($matches[0] ?? []));
    $allowed = ['{app_name}'];
    $unknown = array_values(array_diff($used, $allowed));
    return ['ok' => !$unknown, 'unknown' => $unknown];
}

function render_environment_error(Throwable $e, array $config, string $phase = 'unknown'): never
{
    http_response_code(503);
    $sqlite = extension_loaded('pdo_sqlite') && extension_loaded('sqlite3');
    $storage = is_dir($config['storage_path']) && is_writable($config['storage_path']);
    $dbDir = dirname($config['database_path']);
    $dbDirWritable = is_dir($dbDir) && is_writable($dbDir);

    // Never expose exception messages containing paths or SQL details to public users.
    $isAdmin = (bool)preg_match('#/(?:admin)(?:/|$)#i', (string)($_SERVER['REQUEST_URI'] ?? ''));

    $issues = [];
    if (!$sqlite) {
        $issues[] = 'Las extensiones PHP pdo_sqlite y sqlite3 no están habilitadas.';
    }

    $storagePath = (string)$config['storage_path'];
    $dbDir = dirname($config['database_path']);
    $storageExists = is_dir($storagePath);
    $storageWritable = $storageExists && is_writable($storagePath);
    $dbDirExists = is_dir($dbDir);
    $dbDirWritable = $dbDirExists && is_writable($dbDir);

    if (!$storageExists) {
        $issues[] = 'No existe la carpeta storage/.';
    } elseif (!$storageWritable) {
        @chmod($storagePath, 0770);
        $storageWritable = is_writable($storagePath);
        if (!$storageWritable) $issues[] = 'PHP no puede escribir en storage/. Revisa permisos/propietario del hosting.';
    }

    if (!$dbDirExists && $storageWritable) {
        @mkdir($dbDir, 0770, true);
        $dbDirExists = is_dir($dbDir);
        $dbDirWritable = $dbDirExists && is_writable($dbDir);
    }
    if (!$dbDirWritable && $sqlite) {
        foreach ([0755, 0775, 0770] as $mode) {
            @chmod($dbDir, $mode);
            if (is_writable($dbDir)) { $dbDirWritable=true; break; }
        }
        if (!$dbDirWritable) {
            $issues[] = 'PHP no puede escribir en db/. Revisa propietario/grupo y permisos del directorio.';
        }
    }

    if ($dbDirWritable && $sqlite && is_file($config['database_path']) && !is_writable($config['database_path'])) {
        foreach ([0664, 0660] as $mode) {
            @chmod($config['database_path'], $mode);
            if (is_writable($config['database_path'])) break;
        }
        if (!is_writable($config['database_path'])) {
            $issues[] = 'PHP puede escribir en db/ pero no puede escribir en fileshare.sqlite.';
        }
    }

    if (!$issues) {
        $phaseMessages = [
            'storage' => 'No se pudo inicializar el almacenamiento.',
            'database' => 'No se pudo abrir o inicializar la base SQLite principal en db/.',
            'settings' => 'La base SQLite principal abrió, pero falló al inicializar su configuración.',
            'configuration' => 'La base abrió, pero falló la carga de la configuración de la plataforma.',
            'maintenance' => 'La base abrió, pero falló la evaluación del estado de mantenimiento.',
            'audit' => 'La aplicación abrió la base principal, pero falló la inicialización del registro de auditoría.',
        ];
        $issues[] = $phaseMessages[$phase] ?? 'La aplicación no pudo completar la inicialización.';
    }

    $title = $isAdmin ? 'El portal necesita configuración' : 'Servicio temporalmente no disponible';
    ?>
    <!doctype html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <script src="<?= e(app_url($config, 'assets/theme.js')) ?>"></script>
        <title><?= e($title) ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
        <link rel="stylesheet" href="<?= e(app_url($config, 'assets/app.css')) ?>">
    </head>
    <body>
    <main class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-7">
                <div class="card border-0 shadow-sm p-4 p-md-5">
                    <div class="text-center">
                        <div class="hero-icon mx-auto mb-3"><i class="bi bi-tools"></i></div>
                        <h1 class="h3"><?= e($title) ?></h1>
                        <p class="text-body-secondary">La aplicación no pudo iniciar correctamente.</p>
                    </div>
                    <div class="alert alert-warning">
                        <div class="fw-semibold mb-2">Requisitos detectados</div>
                        <ul class="mb-0">
                            <?php foreach ($issues as $issue): ?>
                                <li><?= e($issue) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php if ($isAdmin): ?>
                        <div class="small text-body-secondary mt-3">Diagnóstico de inicialización: <code><?= e($phase) ?></code>. El detalle técnico se registra en <code>storage/.bootstrap-error.log</code> y no se expone públicamente.</div>
                    <?php endif; ?>
                    <div class="small text-body-secondary">
                        <strong>Requisitos:</strong> PHP 8.2+, PDO SQLite, SQLite3 y permisos de escritura en
                        <code>storage/</code>, <code>storage/files/</code>, <code>storage/tmp/</code> y <code>db/</code>.
                    </div>
                    <?php if ($isAdmin): ?>
                        <a class="btn btn-outline-primary mt-4" href="<?= e(app_base_url($config)) ?>/">Volver al portal</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    </body>
    </html>
    <?php
    exit;
}

function supported_languages(): array
{
    return ['en' => 'English', 'es' => 'Español'];
}

function browser_language(): ?string
{
    $raw = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $parts = preg_split('/\s*,\s*/', strtolower($raw)) ?: [];
    foreach ($parts as $part) {
        $token = trim(explode(';', $part)[0]);
        $lang = substr($token, 0, 2);
        if (isset(supported_languages()[$lang])) {
            return $lang;
        }
    }
    return null;
}

function app_language(PDO $db, array $config): string
{
    $allowed = array_keys(supported_languages());
    $saved = (string)setting($db, 'default_language', (string)($config['default_language'] ?? 'en'));
    return in_array($saved, $allowed, true) ? $saved : 'en';
}


function resolve_language(PDO $db, array $config, ?string $requested = null): string
{
    $allowed = array_keys(supported_languages());

    if ($requested !== null) {
        $requested = strtolower(substr(trim($requested), 0, 2));
        if (in_array($requested, $allowed, true)) {
            return $requested;
        }
    }

    // Browser preference first. This is the normal public-facing default.
    $browser = browser_language();
    if ($browser !== null && in_array($browser, $allowed, true)) {
        return $browser;
    }

    // Platform default is the fallback.
    $fallback = (string)setting($db, 'default_language', (string)($config['default_language'] ?? 'en'));
    return in_array($fallback, $allowed, true) ? $fallback : 'en';
}

function share_template_key(string $language): string
{
    return strtolower(substr($language, 0, 2)) === 'es' ? 'share_template_es' : 'share_template_en';
}

function default_share_template_for_language(string $language): string
{
    if (strtolower(substr($language, 0, 2)) === 'es') {
        return "{app_name}\n\nArchivo: {filename}\n\nDescarga:\n{url}\n\nPIN:\n{pin}\n\nSHA-256:\n{sha256}\n\nEl archivo estará disponible durante {duration}.\nExpira: {expires_at} ({expires_at_iso}).\nDuración: {duration_hours} horas";
    }
    return "{app_name}\n\nFile: {filename}\n\nDownload:\n{url}\n\nPIN:\n{pin}\n\nSHA-256:\n{sha256}\n\nThe file will be available for {duration}.\nExpires: {expires_at} ({expires_at_iso}).\nDuration: {duration_hours} hours";
}

function get_share_template(PDO $db, string $language): string
{
    $key = share_template_key($language);
    $template = trim((string)setting($db, $key, default_share_template_for_language($language)));
    return $template !== '' ? $template : default_share_template_for_language($language);
}

function t(string $key, string $lang = 'en'): string
{
    static $cache = [];
    $lang = in_array($lang, ['en', 'es'], true) ? $lang : 'en';
    if (!isset($cache[$lang])) {
        $file = dirname(__DIR__) . '/lang/' . $lang . '.php';
        $cache[$lang] = is_file($file) ? require $file : [];
    }
    return $cache[$lang][$key] ?? $key;
}


function get_setting_int(PDO $db, string $key, int $default): int
{
    $v = setting($db, $key, (string)$default);
    return max(0, (int)$v);
}

function client_ip_address(): string
{
    // Do not trust X-Forwarded-For by default; admin can deploy behind a proxy
    // and explicitly configure a trusted proxy later if desired.
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function antivirus_command_template(PDO $db): string
{
    return trim((string)setting($db, 'antivirus_command', 'clamdscan --no-summary {file}'));
}

function antivirus_enabled(PDO $db): bool
{
    return setting($db, 'antivirus_enabled', '0') === '1';
}

function validate_antivirus_command_template(string $command): string
{
    $command = trim($command);
    if ($command === '') {
        throw new InvalidArgumentException('El comando del antivirus no puede estar vacío.');
    }
    if (substr_count($command, '{file}') !== 1) {
        throw new InvalidArgumentException('El comando debe contener exactamente el marcador {file}.');
    }
    // Deliberately reject shell operators, substitutions, quotes, redirections
    // and command separators. The only dynamic argument is the safely escaped
    // temporary file path inserted for {file}.
    if (preg_match('/[;&|`$<>\'"\r\n]/', $command)) {
        throw new InvalidArgumentException('El comando contiene caracteres no permitidos por seguridad.');
    }
    $withoutPlaceholder = str_replace('{file}', '', $command);
    if (preg_match('/[^A-Za-z0-9_\.\/:\\\\\-\+\=\,\%\@\s]/', $withoutPlaceholder)) {
        throw new InvalidArgumentException('El comando contiene caracteres no permitidos por seguridad.');
    }
    return $command;
}

function run_antivirus_scan(PDO $db, array $config, string $filePath): array
{
    if (!antivirus_enabled($db)) {
        return [
            'status' => 'disabled',
            'message' => 'El análisis antivirus está desactivado.',
            'detail' => '',
        ];
    }
    if (!is_file($filePath) || !is_readable($filePath)) {
        return [
            'status' => 'error',
            'message' => 'El archivo temporal no está disponible para el antivirus.',
            'detail' => '',
        ];
    }

    try {
        $template = validate_antivirus_command_template(antivirus_command_template($db));
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'message' => 'La configuración del antivirus no es válida.',
            'detail' => $e->getMessage(),
        ];
    }

    $command = str_replace('{file}', escapeshellarg($filePath), $template) . ' 2>&1';
    $output = shell_exec($command);

    if ($output === null) {
        return [
            'status' => 'error',
            'message' => 'No se pudo ejecutar el antivirus.',
            'detail' => '',
        ];
    }

    $detail = trim($output);
    if ($detail !== '' && stripos($detail, 'FOUND') !== false) {
        return [
            'status' => 'infected',
            'message' => 'El antivirus detectó una amenaza.',
            'detail' => $detail,
        ];
    }
    if ($detail !== '' && preg_match('/(?:^|:)\s*OK\s*$/mi', $detail)) {
        return [
            'status' => 'clean',
            'message' => 'El archivo está limpio.',
            'detail' => $detail,
        ];
    }

    return [
        'status' => 'error',
        'message' => 'No se pudo determinar el resultado del antivirus.',
        'detail' => $detail,
    ];
}

function test_antivirus_configuration(PDO $db, array $config): array
{
    $tmp = @tempnam(sys_get_temp_dir(), 'portal-av-');
    if ($tmp === false) {
        return ['status'=>'error','message'=>'No se pudo crear el archivo temporal de prueba.','detail'=>''];
    }
    try {
        $payload = 'Portal de archivos ClamAV integration test ' . bin2hex(random_bytes(24)) . "\n";
        if (@file_put_contents($tmp, $payload) === false) {
            return ['status'=>'error','message'=>'No se pudo preparar la prueba del antivirus.','detail'=>''];
        }
        return run_antivirus_scan($db, $config, $tmp);
    } finally {
        @unlink($tmp);
    }
}

function auth_subject_hash(string $subject): string
{
    return hash('sha256', trim(mb_strtolower($subject)));
}

function failed_auth_window_state(PDO $db, string $scope, string $subject, string $ip, int $limit = 5, int $windowSeconds = 300): array
{
    $limit=max(1,$limit);
    $windowSeconds=max(60,$windowSeconds);
    $windowStart=intdiv(time(),$windowSeconds)*$windowSeconds;
    $subjectHash=auth_subject_hash($subject);
    $db->exec("CREATE TABLE IF NOT EXISTS auth_failed_attempts (
        scope TEXT NOT NULL,
        subject_hash TEXT NOT NULL,
        ip_address TEXT NOT NULL,
        window_start INTEGER NOT NULL,
        attempt_count INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY(scope,subject_hash,ip_address,window_start)
    )");
    $db->prepare('DELETE FROM auth_failed_attempts WHERE window_start < ?')->execute([time()-86400]);
    $st=$db->prepare('SELECT attempt_count FROM auth_failed_attempts WHERE scope=? AND subject_hash=? AND ip_address=? AND window_start=?');
    $st->execute([$scope,$subjectHash,$ip,$windowStart]);
    $row=$st->fetch();
    $count=$row?(int)$row['attempt_count']:0;
    return ['allowed'=>$count<$limit,'count'=>$count,'limit'=>$limit,'window_start'=>$windowStart,'subject_hash'=>$subjectHash,'ip'=>$ip,'scope'=>$scope];
}

function auth_failed_attempt(PDO $db, string $scope, string $subject, string $ip, int $limit = 5, int $windowSeconds = 300): array
{
    $state=failed_auth_window_state($db,$scope,$subject,$ip,$limit,$windowSeconds);
    if(!$state['allowed']) return $state;
    $st=$db->prepare('INSERT INTO auth_failed_attempts(scope,subject_hash,ip_address,window_start,attempt_count) VALUES(?,?,?,?,1)
        ON CONFLICT(scope,subject_hash,ip_address,window_start) DO UPDATE SET attempt_count=attempt_count+1');
    $st->execute([$scope,$state['subject_hash'],$ip,$state['window_start']]);
    $state['count']++;
    $state['allowed']=$state['count']<$state['limit'];
    return $state;
}

function auth_clear_failed_attempts(PDO $db, string $scope, string $subject, string $ip): void
{
    $subjectHash=auth_subject_hash($subject);
    $db->prepare('DELETE FROM auth_failed_attempts WHERE scope=? AND subject_hash=? AND ip_address=?')
       ->execute([$scope,$subjectHash,$ip]);
}

function enforce_upload_rate_limit(PDO $db, array $config): void
{
    $limit = get_setting_int($db, 'upload_rate_limit_per_hour', (int)($config['upload_rate_limit_per_hour'] ?? 20));
    if ($limit === 0) {
        return; // 0 means unlimited.
    }

    $window = get_setting_int($db, 'upload_rate_limit_window_seconds', (int)($config['upload_rate_limit_window_seconds'] ?? 3600));
    $window = max(60, min($window, 86400));
    $now = time();
    $windowStart = intdiv($now, $window) * $window;
    $ip = client_ip_address();

    $db->beginTransaction();
    try {
        // Keep only the current window for this IP.
        $stmt = $db->prepare('DELETE FROM upload_rate_limits WHERE ip_address = ? AND window_start < ?');
        $stmt->execute([$ip, $windowStart]);

        $stmt = $db->prepare('SELECT upload_count FROM upload_rate_limits WHERE ip_address = ? AND window_start = ?');
        $stmt->execute([$ip, $windowStart]);
        $row = $stmt->fetch();

        if ($row) {
            $count = (int)$row['upload_count'];
            if ($count >= $limit) {
                $db->commit();
                throw new RuntimeException('Has alcanzado el límite de cargas por IP para la ventana configurada.');
            }
            $stmt = $db->prepare('UPDATE upload_rate_limits SET upload_count = upload_count + 1 WHERE ip_address = ? AND window_start = ?');
            $stmt->execute([$ip, $windowStart]);
        } else {
            $stmt = $db->prepare('INSERT INTO upload_rate_limits(ip_address, window_start, upload_count) VALUES(?,?,1)');
            $stmt->execute([$ip, $windowStart]);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function cleanup_upload_rate_limits(PDO $db, int $olderThanSeconds = 172800): void
{
    $cutoff = time() - max(3600, $olderThanSeconds);
    $stmt = $db->prepare('DELETE FROM upload_rate_limits WHERE window_start < ?');
    $stmt->execute([$cutoff]);
}


function enforce_api_key_rate_limit(PDO $db, int $apiKeyId, int $limitPerHour): void
{
    if ($limitPerHour <= 0) {
        return; // 0 = unlimited for this secret
    }

    $now = time();
    $windowStart = intdiv($now, 3600) * 3600;
    $db->beginTransaction();
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS api_key_rate_limits (
                api_key_id INTEGER NOT NULL,
                window_start INTEGER NOT NULL,
                request_count INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (api_key_id, window_start),
                FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
            )
        ");

        $stmt = $db->prepare('DELETE FROM api_key_rate_limits WHERE api_key_id = ? AND window_start < ?');
        $stmt->execute([$apiKeyId, $windowStart]);

        $stmt = $db->prepare('SELECT request_count FROM api_key_rate_limits WHERE api_key_id = ? AND window_start = ?');
        $stmt->execute([$apiKeyId, $windowStart]);
        $row = $stmt->fetch();

        if ($row && (int)$row['request_count'] >= $limitPerHour) {
            $db->commit();
            throw new RuntimeException('Este secret alcanzó su límite de cargas por hora.');
        }

        if ($row) {
            $stmt = $db->prepare('UPDATE api_key_rate_limits SET request_count = request_count + 1 WHERE api_key_id = ? AND window_start = ?');
            $stmt->execute([$apiKeyId, $windowStart]);
        } else {
            $stmt = $db->prepare('INSERT INTO api_key_rate_limits(api_key_id, window_start, request_count) VALUES(?,?,1)');
            $stmt->execute([$apiKeyId, $windowStart]);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}


function api_scopes_all(): array
{
    return ['files.upload', 'files.read', 'files.delete'];
}

function api_scopes_decode(?string $json): array
{
    $allowed = api_scopes_all();
    $data = json_decode((string)$json, true);
    if (!is_array($data)) return [];
    return array_values(array_intersect($allowed, array_map('strval', $data)));
}

function api_scope_allows(array $apiKey, string $scope): bool
{
    return in_array($scope, api_scopes_decode($apiKey['scopes_json'] ?? '[]'), true);
}

function require_api_scope(array $apiKey, string $scope, PDO $db, array $config): void
{
    if (!api_scope_allows($apiKey, $scope)) {
        audit_event($db, $config, 'authz_api_scope_failure', 'WARNING', true, 'blocked', 'api',
            (string)$apiKey['id'], 'API scope insuficiente.', ['required_scope'=>$scope], 'api_key', (string)$apiKey['id']);
        json_response([
            'ok'=>false,
            'error'=>'forbidden',
            'message'=>'El secret no tiene el scope requerido.',
            'required_scope'=>$scope
        ], 403);
    }
}

function api_enforce_quotas(PDO $db, array $apiKey, int $size): void
{
    $dailyFiles = max(0, (int)($apiKey['quota_files_per_day'] ?? 0));
    $dailyBytes = max(0, (int)($apiKey['quota_bytes_per_day'] ?? 0));

    if ($dailyFiles > 0 || $dailyBytes > 0) {
        $date = gmdate('Y-m-d');
        $db->beginTransaction();
        try {
            $st = $db->prepare('SELECT file_count, byte_count FROM api_key_daily_usage WHERE api_key_id=? AND usage_date=?');
            $st->execute([(int)$apiKey['id'], $date]);
            $row = $st->fetch();
            $files = $row ? (int)$row['file_count'] : 0;
            $bytes = $row ? (int)$row['byte_count'] : 0;
            if (($dailyFiles > 0 && $files >= $dailyFiles) || ($dailyBytes > 0 && ($bytes + $size) > $dailyBytes)) {
                $db->commit();
                throw new RuntimeException('Este secret alcanzó su cuota diaria.');
            }
            if ($row) {
                $db->prepare('UPDATE api_key_daily_usage SET file_count=file_count+1, byte_count=byte_count+? WHERE api_key_id=? AND usage_date=?')
                    ->execute([$size, (int)$apiKey['id'], $date]);
            } else {
                $db->prepare('INSERT INTO api_key_daily_usage(api_key_id,usage_date,file_count,byte_count) VALUES(?,?,1,?)')
                    ->execute([(int)$apiKey['id'], $date, $size]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}

function sha256_file_hex(string $path): string
{
    $hash = hash_file('sha256', $path);
    if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
        throw new RuntimeException('No se pudo calcular la huella SHA-256.');
    }
    return $hash;
}

function api_scope_label(string $scope, string $language): string
{
    $labels = [
        'es' => [
            'files.upload' => 'Subir archivos',
            'files.read' => 'Consultar archivos',
            'files.delete' => 'Eliminar archivos',
        ],
        'en' => [
            'files.upload' => 'Upload files',
            'files.read' => 'View files',
            'files.delete' => 'Delete files',
        ],
    ];
    return $labels[$language][$scope] ?? $scope;
}
function api_scope_description(string $scope, string $language): string
{
    $labels = [
        'es' => [
            'files.upload' => 'Permite cargar archivos mediante la API.',
            'files.read' => 'Permite consultar metadatos de archivos creados por este secret.',
            'files.delete' => 'Permite eliminar archivos creados por este secret.',
        ],
        'en' => [
            'files.upload' => 'Allows uploading files through the API.',
            'files.read' => 'Allows reading metadata of files created by this secret.',
            'files.delete' => 'Allows deleting files created by this secret.',
        ],
    ];
    return $labels[$language][$scope] ?? $scope;
}
