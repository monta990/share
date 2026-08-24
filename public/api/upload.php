<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
require_post(); verify_csrf();

$raw=file_get_contents('php://input'); $payload=json_decode($raw?:'{}',true) ?: [];
$action=$payload['action']??'';

if($action==='init'){
    try { enforce_upload_rate_limit($db,$config); } catch(RuntimeException $e) { json_response(['ok'=>false,'message'=>$e->getMessage()],429); }
    $filename=clean_original_name((string)($payload['filename']??'')); $size=filter_var($payload['size']??null,FILTER_VALIDATE_INT);
    $mime=trim((string)($payload['mime']??'application/octet-stream'));
    $useDefaults=filter_var($payload['use_defaults']??true,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
    if($useDefaults===null) $useDefaults=true;
    $requestedDuration=filter_var($payload['duration_hours']??null,FILTER_VALIDATE_INT);
    $requestedMaxDownloads=filter_var($payload['max_downloads']??null,FILTER_VALIDATE_INT);
    $requestedOneTime=filter_var($payload['one_time']??null,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
    if(!$useDefaults){
        if($requestedDuration===false || $requestedDuration===null || $requestedDuration<1 || $requestedDuration>8760)
            json_response(['ok'=>false,'message'=>'La duración debe estar entre 1 y 8760 horas.'],422);
        if($requestedMaxDownloads===false || $requestedMaxDownloads===null || $requestedMaxDownloads<0 || $requestedMaxDownloads>1000000)
            json_response(['ok'=>false,'message'=>'El máximo de descargas debe estar entre 0 y 1,000,000.'],422);
        if($requestedOneTime===null)
            json_response(['ok'=>false,'message'=>'El valor de un solo uso no es válido.'],422);
        if($requestedOneTime) $requestedMaxDownloads=1;
    }

    if($filename==='')json_response(['ok'=>false,'message'=>'El nombre del archivo no es válido.'],422);
    if($size===false||$size<=0)json_response(['ok'=>false,'message'=>'El tamaño del archivo no es válido.'],422);
    if($size>$config['max_file_size'])json_response(['ok'=>false,'message'=>'El archivo supera el tamaño máximo configurado.'],413);
    try { ensure_available_space($config,(int)$size); } catch(Throwable $e) { json_response(['ok'=>false,'message'=>$e->getMessage()],507); }
    $id=bin2hex(random_bytes(24));$tmp=$config['storage_path'].DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.$id.'.part';
    if(file_put_contents($tmp,'')===false)json_response(['ok'=>false,'message'=>'No se pudo iniciar el almacenamiento temporal.'],500);
    
    $activeUploads = active_session_uploads($config);
    if ($activeUploads >= 8) {
        @unlink($tmp);
        json_response(['ok'=>false,'message'=>'Hay demasiadas subidas activas en esta sesión. Espera a que termine una o vuelve a intentarlo.'],429);
    }
    $_SESSION['uploads'][$id]=[
        'filename'=>$filename,
        'size'=>$size,
        'mime'=>'application/octet-stream',
        'tmp'=>$tmp,
        'received'=>0,
        'created'=>time(),
        'next_index'=>0,
        'use_defaults'=>$useDefaults,
        'duration_hours'=>$useDefaults?null:(int)$requestedDuration,
        'max_downloads'=>$useDefaults?null:(int)$requestedMaxDownloads,
        'one_time'=>$useDefaults?null:(bool)$requestedOneTime
    ];
    audit_event($db, $config, 'file_upload_init', 'INFO', true, 'success', 'web', null, 'Inicio de carga web.', ['size'=>$size]);
    json_response(['ok'=>true,'upload_id'=>$id]);
}
cleanup_session_uploads($config);
if($action==='complete'){
    $id=(string)($payload['upload_id']??'');$meta=$_SESSION['uploads'][$id]??null;
if(!$meta||!is_file($meta['tmp'])){ unset($_SESSION['uploads'][$id]); json_response(['ok'=>false,'message'=>'La sesión de subida no existe o expiró.'],404); }
    if((int)filesize($meta['tmp'])!==$meta['size']){@unlink($meta['tmp']);unset($_SESSION['uploads'][$id]);json_response(['ok'=>false,'message'=>'El tamaño recibido no coincide con el archivo original.'],400);}
    $detected=detect_mime_type($meta['tmp']);
    $scan=run_antivirus_scan($db,$config,$meta['tmp']);
    $antivirusScanned = ($scan['status']==='clean') ? 1 : 0;
    if($scan['status']==='infected'){
        audit_event($db,$config,'file_antivirus_scan','WARNING',true,'failure','web',null,
            'El antivirus rechazó una carga web por contenido malicioso.', ['status'=>'infected']);
        @unlink($meta['tmp']); unset($_SESSION['uploads'][$id]);
        json_response(['ok'=>false,'message'=>'El archivo fue rechazado porque el antivirus detectó una amenaza.'],422);
    }
    if($scan['status']==='error'){
        audit_event($db,$config,'file_antivirus_scan','ERROR',true,'error','web',null,
            'No se pudo completar el análisis antivirus de una carga web.', ['status'=>'error']);
        @unlink($meta['tmp']); unset($_SESSION['uploads'][$id]);
        json_response(['ok'=>false,'message'=>'No se pudo verificar el archivo con el antivirus. La carga no fue publicada.'],503);
    }
    if($scan['status']==='clean'){
        audit_event($db,$config,'file_antivirus_scan','INFO',true,'success','web',null,
            'La carga web superó el análisis antivirus.', ['status'=>'clean']);
    }
    $sha256=sha256_file_hex($meta['tmp']);
    $final=hash('sha256',$sha256.bin2hex(random_bytes(32)));$path=safe_file_path($config,$final);
    if(!rename($meta['tmp'],$path)){@unlink($meta['tmp']);unset($_SESSION['uploads'][$id]);json_response(['ok'=>false,'message'=>'No se pudo finalizar el archivo.'],500);}
    $download=random_download_id();$pin=generate_pin();$hash=password_hash($pin,PASSWORD_DEFAULT);$now=new DateTimeImmutable('now', new DateTimeZone('UTC'));$defaultDuration=max(1,(int)setting($db,'duration_hours',(string)$config['expiration_hours']));$defaultMax=max(0,(int)setting($db,'default_max_downloads','0'));$defaultOne=(bool)((int)setting($db,'default_one_time','0'));if($defaultOne)$defaultMax=1;$durationHours=(!empty($meta['use_defaults']))?$defaultDuration:max(1,(int)$meta['duration_hours']);$effectiveMax=(!empty($meta['use_defaults']))?$defaultMax:max(0,(int)$meta['max_downloads']);$effectiveOne=(!empty($meta['use_defaults']))?$defaultOne:(bool)$meta['one_time'];if($effectiveOne)$effectiveMax=1;$expires=$now->modify('+' . $durationHours . ' hours');
    try{$s=$db->prepare('INSERT INTO files(download_id,original_name,stored_name,mime_type,file_size,sha256,pin_hash,expires_at,created_at,downloads,max_downloads,one_time,antivirus_scanned) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');$s->execute([$download,$meta['filename'],$final,$detected,$meta['size'],$sha256,$hash,utc_storage_datetime($expires),utc_storage_datetime($now),0,$effectiveMax,$effectiveOne?1:0,$antivirusScanned]);}
    catch(Throwable $e){@unlink($path);unset($_SESSION['uploads'][$id]);throw $e;}
    unset($_SESSION['uploads'][$id]);
    $url=app_base_url($config).'/f/'.$download;
    $durationLabel=$durationHours.' horas';
    $shareLanguage=resolve_language($db,$config,(string)($_SERVER['HTTP_X_LANGUAGE']??'')); $template=get_share_template($db,$shareLanguage);
    $shareText=render_share_template($db,$template,[
        'filename'=>$meta['filename'],'url'=>$url,'pin'=>$pin,
        'expires_at'=>format_local_datetime($expires, $config),
        'expires_at_iso'=>$expires->format(DateTimeInterface::ATOM),
        'sha256'=>$sha256,
        'duration'=>$durationLabel,'duration_hours'=>(string)$durationHours
    ]);
    audit_event($db, $config, 'file_upload', 'INFO', true, 'success', 'web', null, 'Archivo cargado desde el portal.', ['size'=>(int)$meta['size'],'mime'=>$detected], 'file', (string)$db->lastInsertId());
    json_response(['ok'=>true,'url'=>$url,'pin'=>$pin,'sha256'=>$sha256,'expires_at'=>format_local_datetime($expires, $config),'share_text'=>$shareText,'language'=>$shareLanguage,'duration_hours'=>$durationHours,'max_downloads'=>$effectiveMax,'one_time'=>$effectiveOne]);
}

cleanup_session_uploads($config);
$id=$_SERVER['HTTP_X_UPLOAD_ID']??'';$index=filter_var($_SERVER['HTTP_X_CHUNK_INDEX']??null,FILTER_VALIDATE_INT);$total=filter_var($_SERVER['HTTP_X_TOTAL_CHUNKS']??null,FILTER_VALIDATE_INT);
if($id===''||$index===false||$total===false||$index<0||$total<1||$index>=$total)json_response(['ok'=>false,'message'=>'Datos de subida inválidos.'],422);
$meta=$_SESSION['uploads'][$id]??null;
if ($meta && (int)($meta['next_index'] ?? 0) !== (int)$index) json_response(['ok'=>false,'message'=>'Parte fuera de orden.'],409);
if(!$meta||!is_file($meta['tmp'])){ unset($_SESSION['uploads'][$id]); json_response(['ok'=>false,'message'=>'La sesión de subida no existe o expiró.'],404); }
if(time()-$meta['created']>21600){@unlink($meta['tmp']);unset($_SESSION['uploads'][$id]);json_response(['ok'=>false,'message'=>'La sesión de subida expiró.'],410);}
$in=fopen('php://input','rb');$out=fopen($meta['tmp'],'ab');if(!$in||!$out)json_response(['ok'=>false,'message'=>'No se pudo escribir la parte recibida.'],500);
$written=0;while(!feof($in)){ $buf=fread($in,1024*1024);if($buf===false||$buf==='')continue;$len=strlen($buf);if($meta['received']+$written+$len>$meta['size']){fclose($in);fclose($out);json_response(['ok'=>false,'message'=>'El archivo excede el tamaño declarado.'],413);}if(fwrite($out,$buf)===false){fclose($in);fclose($out);json_response(['ok'=>false,'message'=>'Error escribiendo el archivo.'],500);}$written+=$len;}
fclose($in);fclose($out);$meta['received']+=$written;$meta['next_index']=(int)$index+1;$_SESSION['uploads'][$id]=$meta;
json_response(['ok'=>true,'chunk'=>$index,'received'=>$meta['received']]);
