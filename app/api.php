<?php
declare(strict_types=1);

function get_api_key_from_request(): string {
    $key = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($key === '') {
        $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) $key = trim($m[1]);
    }
    return $key;
}

function authenticate_api_key(PDO $db): array {
    $plain = get_api_key_from_request();
    if ($plain === '' || strlen($plain) < 24) {
        audit_event($db, $GLOBALS['config'], 'authn_api_missing', 'WARNING', true, 'failure', 'api', null, 'Solicitud API sin credencial válida.');
        json_response(['ok'=>false,'error'=>'unauthorized','message'=>'API key requerida.'],401);
    }

    $hash = hash('sha256', $plain);
    $s = $db->prepare('SELECT * FROM api_keys WHERE key_hash=? AND revoked_at IS NULL LIMIT 1');
    $s->execute([$hash]);
    $row = $s->fetch();

    if (!$row || !hash_equals((string)$row['key_hash'], $hash)) {
        audit_event($db, $GLOBALS['config'], 'authn_api_failure', 'WARNING', true, 'failure', 'api', null, 'API key inválida o revocada.');
        json_response(['ok'=>false,'error'=>'unauthorized','message'=>'API key inválida o revocada.'],401);
    }

    try {
        enforce_api_key_rate_limit($db,(int)$row['id'],(int)$row['requests_per_hour']);
    } catch (RuntimeException $e) {
        audit_event($db, $GLOBALS['config'], 'authn_api_rate_limited', 'WARNING', true, 'blocked', 'api', (string)$row['id'], 'API limitada por el máximo de solicitudes por hora.');
        json_response(['ok'=>false,'error'=>'rate_limited','message'=>'Este secret alcanzó su límite de solicitudes por hora.'],429);
    }

    $db->prepare('UPDATE api_keys SET last_used_at=?, request_count=request_count+1 WHERE id=?')
       ->execute([(new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), $row['id']]);

    audit_event($db, $GLOBALS['config'], 'authn_api_success', 'INFO', true, 'success', 'api', (string)$row['id'], 'API autenticada correctamente.', [], 'api_key', (string)$row['id']);
    return $row;
}

function api_upload_policy(PDO $db, array $apiKey, int $size): void
{
    require_api_scope($apiKey, 'files.upload', $db, $GLOBALS['config']);
    try {
        enforce_upload_rate_limit($db, $GLOBALS['config']);
    } catch (RuntimeException $e) {
        json_response(['ok'=>false,'error'=>'rate_limited','message'=>$e->getMessage()],429);
    }
    try {
        api_enforce_quotas($db, $apiKey, $size);
    } catch (RuntimeException $e) {
        json_response(['ok'=>false,'error'=>'quota_exceeded','message'=>$e->getMessage()],429);
    }
}

function api_store_uploaded_file(
    array $config,
    PDO $db,
    array $apiKey,
    string $originalName,
    string $mime,
    int $size,
    $input,
    ?string $shareTemplate = null,
    ?string $shareLanguage = null,
    ?int $maxDownloads = null,
    ?bool $oneTime = null,
    ?int $durationHoursOverride = null
): array {
    if ($originalName === '') $originalName = 'archivo';
    if ($size <= 0) json_response(['ok'=>false,'error'=>'invalid_file','message'=>'El archivo está vacío.'],422);
    if ($size > (int)$config['max_file_size']) json_response(['ok'=>false,'error'=>'file_too_large','message'=>'El archivo supera el tamaño máximo configurado.'],413);

    api_upload_policy($db, $apiKey, $size);

    try { ensure_available_space($config, $size); }
    catch (Throwable $e) { json_response(['ok'=>false,'error'=>'insufficient_storage','message'=>$e->getMessage()],507); }

    $tmp = $config['storage_path'].DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'api_'.bin2hex(random_bytes(20)).'.part';
    $out = fopen($tmp,'wb');
    if (!$out) json_response(['ok'=>false,'error'=>'storage_error','message'=>'No se pudo crear el archivo temporal.'],500);

    $written=0;
    while (!feof($input)) {
        $buf=fread($input,1024*1024);
        if ($buf===false) {
            fclose($out); @unlink($tmp);
            json_response(['ok'=>false,'error'=>'storage_error','message'=>'Error leyendo el archivo.'],500);
        }
        if ($buf==='') continue;
        $len=strlen($buf);
        if ($written+$len > $size) {
            fclose($out); @unlink($tmp);
            json_response(['ok'=>false,'error'=>'size_mismatch','message'=>'El tamaño recibido supera el declarado.'],400);
        }
        $n=fwrite($out,$buf);
        if ($n===false || $n!==$len) {
            fclose($out); @unlink($tmp);
            json_response(['ok'=>false,'error'=>'storage_error','message'=>'Error guardando el archivo.'],500);
        }
        $written += $n;
    }
    fclose($out);

    if ($written !== $size) {
        @unlink($tmp);
        json_response(['ok'=>false,'error'=>'size_mismatch','message'=>'El tamaño recibido no coincide con el declarado.'],400);
    }

    $detectedMime = detect_mime_type($tmp);
    $scan = run_antivirus_scan($db, $config, $tmp);
    $antivirusScanned = ($scan['status'] === 'clean') ? 1 : 0;
    if ($scan['status'] === 'infected') {
        audit_event($db, $GLOBALS['config'], 'file_antivirus_scan', 'WARNING', true, 'failure', 'api', (string)$apiKey['id'],
            'El antivirus rechazó una carga API por contenido malicioso.', ['status'=>'infected'], 'api_key', (string)$apiKey['id']);
        @unlink($tmp);
        json_response(['ok'=>false,'error'=>'antivirus_detected','message'=>'El archivo fue rechazado porque el antivirus detectó una amenaza.'],422);
    }
    if ($scan['status'] === 'error') {
        audit_event($db, $GLOBALS['config'], 'file_antivirus_scan', 'ERROR', true, 'error', 'api', (string)$apiKey['id'],
            'No se pudo completar el análisis antivirus de una carga API.', ['status'=>'error'], 'api_key', (string)$apiKey['id']);
        @unlink($tmp);
        json_response(['ok'=>false,'error'=>'antivirus_unavailable','message'=>'No se pudo verificar el archivo con el antivirus. La carga no fue publicada.'],503);
    }
    if ($scan['status'] === 'clean') {
        audit_event($db, $GLOBALS['config'], 'file_antivirus_scan', 'INFO', true, 'success', 'api', (string)$apiKey['id'],
            'La carga API superó el análisis antivirus.', ['status'=>'clean'], 'api_key', (string)$apiKey['id']);
    }
    $sha256 = sha256_file_hex($tmp);

    $stored = $sha256;
    if (is_file($config['storage_path'].DIRECTORY_SEPARATOR.'files'.DIRECTORY_SEPARATOR.$stored)) {
        $stored = substr(hash('sha256',$stored.bin2hex(random_bytes(16))),0,64);
    }

    $final=safe_file_path($config,$stored);
    if (!rename($tmp,$final)) {
        @unlink($tmp);
        json_response(['ok'=>false,'error'=>'storage_error','message'=>'No se pudo finalizar el archivo.'],500);
    }

    $downloadId=random_download_id();
    $pin=generate_pin();
    $now=new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $durationHours=$durationHoursOverride!==null?max(1,min(8760,(int)$durationHoursOverride)):max(1,(int)setting($db,'duration_hours',(string)$config['expiration_hours']));
    $expires=$now->modify('+'.$durationHours.' hours');

    $globalMax=max(0,(int)setting($db,'default_max_downloads','0'));
    $globalOne=(bool)((int)setting($db,'default_one_time','0'));

    if ($oneTime === true) {
        $effectiveOne = true;
        $effectiveMax = 1;
    } else {
        $effectiveOne = false;
        $effectiveMax = ($maxDownloads !== null) ? max(0,$maxDownloads) : $globalMax;
    }

    try {
        $s=$db->prepare('INSERT INTO files(download_id,original_name,stored_name,mime_type,file_size,sha256,pin_hash,expires_at,created_at,downloads,max_downloads,one_time,api_key_id,antivirus_scanned) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute([
            $downloadId,$originalName,$stored,$detectedMime,$size,$sha256,password_hash($pin,PASSWORD_DEFAULT),
            utc_storage_datetime($expires),utc_storage_datetime($now),0,$effectiveMax,$effectiveOne?1:0,$apiKey['id'],$antivirusScanned
        ]);
    } catch(Throwable $e) {
        @unlink($final);
        throw $e;
    }

    $url=rtrim(app_base_url($config),'/').'/f/'.$downloadId;
    $shareLanguage = $shareLanguage ?: resolve_language($db,$config,null);
    $template = $shareTemplate;
    if ($template===null || trim($template)==='') $template=get_share_template($db,$shareLanguage);
    $validation=validate_share_template($template);
    if(!$validation['ok']) $template=default_share_template_for_language($shareLanguage);

    $durationLabel=$durationHours.' horas';
    if ($shareLanguage === 'en') $durationLabel=$durationHours.' hours';

    $shareText=render_share_template($db,$template,[
        'filename'=>$originalName,'url'=>$url,'pin'=>$pin,
        'expires_at'=>format_local_datetime($expires, $config),
        'expires_at_iso'=>$expires->format(DateTimeInterface::ATOM),
        'sha256'=>$sha256,
        'duration'=>$durationLabel,'duration_hours'=>(string)$durationHours
    ]);

    $fileId=(int)$db->lastInsertId();
    audit_event($db, $config, 'file_upload', 'INFO', true, 'success', 'api', (string)$apiKey['id'],
        'Archivo cargado mediante API.', ['size'=>$size,'mime'=>$detectedMime,'sha256'=>$sha256], 'file', (string)$fileId);

    return [
        'id'=>$fileId,
        'url'=>$url,
        'pin'=>$pin,
        'expires_at'=>$expires->format(DateTimeInterface::ATOM),
        'expires_at_local'=>format_local_datetime($expires, $config),
        'filename'=>$originalName,
        'size'=>$size,
        'sha256'=>$sha256,
        'share_text'=>$shareText,
        'language'=>$shareLanguage,
        'downloads'=>0,
        'max_downloads'=>$effectiveMax,
        'one_time'=>$effectiveOne
    ];
}

function api_find_file(PDO $db, string $identifier, int $apiKeyId): ?array
{
    $s=$db->prepare('SELECT * FROM files WHERE api_key_id=? AND (download_id=? OR CAST(id AS TEXT)=?) LIMIT 1');
    $s->execute([$apiKeyId,$identifier,$identifier]);
    $row=$s->fetch();
    return $row ?: null;
}
