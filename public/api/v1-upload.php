<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
require_once dirname(__DIR__,2).'/app/api.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    json_response(['ok'=>false,'error'=>'method_not_allowed','message'=>'Usa POST.'],405);
}

$apiKey=authenticate_api_key($db);

$requestedLanguage=null;
$shareTemplate=null;
$maxDownloads=null;
$oneTime=null;
$durationHours=null;

if (!empty($_FILES['file'])) {
    $f=$_FILES['file'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['ok'=>false,'error'=>'upload_error','message'=>'PHP no pudo recibir el archivo. Código: '.(int)$f['error']],400);
    }
    $name=clean_original_name((string)$f['name']);
    $size=(int)$f['size'];
    $input=fopen($f['tmp_name'],'rb');
    if (!$input) json_response(['ok'=>false,'error'=>'storage_error','message'=>'No se pudo leer el archivo recibido.'],500);
    $requestedLanguage=isset($_POST['language']) ? trim((string)$_POST['language']) : trim((string)($_SERVER['HTTP_X_LANGUAGE']??''));
    $shareLanguage=resolve_language($db,$config,$requestedLanguage!==''?$requestedLanguage:null);
    $shareTemplate=isset($_POST['share_template']) ? trim((string)$_POST['share_template']) : null;
    $maxDownloads=isset($_POST['max_downloads']) ? filter_var($_POST['max_downloads'],FILTER_VALIDATE_INT) : null;
    $oneTime=isset($_POST['one_time']) ? filter_var($_POST['one_time'],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE) : null;
    $durationHours=isset($_POST['duration_hours']) ? filter_var($_POST['duration_hours'],FILTER_VALIDATE_INT) : null;
    if($durationHours!==null && ($durationHours===false || $durationHours<1 || $durationHours>8760)) json_response(['ok'=>false,'error'=>'invalid_duration_hours','message'=>'duration_hours debe estar entre 1 y 8760.'],422);
    if($maxDownloads!==null && ($maxDownloads===false || $maxDownloads<0 || $maxDownloads>1000000)) json_response(['ok'=>false,'error'=>'invalid_max_downloads','message'=>'max_downloads inválido.'],422);
} else {
    $size=(int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $name=clean_original_name((string)($_SERVER['HTTP_X_FILENAME'] ?? 'archivo'));
    $input=fopen('php://input','rb');
    if (!$input) json_response(['ok'=>false,'error'=>'storage_error','message'=>'No se pudo leer el archivo recibido.'],500);
    $requestedLanguage=trim((string)($_SERVER['HTTP_X_LANGUAGE']??''));
    $shareLanguage=resolve_language($db,$config,$requestedLanguage!==''?$requestedLanguage:null);
    $shareTemplate=trim((string)($_SERVER['HTTP_X_SHARE_TEMPLATE'] ?? ''));
    $shareTemplate=$shareTemplate!==''?$shareTemplate:null;
    if(isset($_SERVER['HTTP_X_MAX_DOWNLOADS'])) {
        $maxDownloads=filter_var($_SERVER['HTTP_X_MAX_DOWNLOADS'],FILTER_VALIDATE_INT);
        if($maxDownloads===false || $maxDownloads<0 || $maxDownloads>1000000) json_response(['ok'=>false,'error'=>'invalid_max_downloads','message'=>'X-Max-Downloads inválido.'],422);
    }
    if(isset($_SERVER['HTTP_X_ONE_TIME'])) {
        $oneTime=filter_var($_SERVER['HTTP_X_ONE_TIME'],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
    }
    if(isset($_SERVER['HTTP_X_DURATION_HOURS'])) {
        $durationHours=filter_var($_SERVER['HTTP_X_DURATION_HOURS'],FILTER_VALIDATE_INT);
        if($durationHours===false || $durationHours<1 || $durationHours>8760) json_response(['ok'=>false,'error'=>'invalid_duration_hours','message'=>'X-Duration-Hours debe estar entre 1 y 8760.'],422);
    }
}

if ($shareTemplate !== null) {
    if (mb_strlen($shareTemplate)>5000) json_response(['ok'=>false,'error'=>'invalid_share_template','message'=>'La plantilla no puede superar 5000 caracteres.'],422);
    $v=validate_share_template($shareTemplate);
    if(!$v['ok']) json_response(['ok'=>false,'error'=>'invalid_share_template','message'=>'La plantilla no es válida.','missing'=>$v['missing'],'unknown'=>$v['unknown']],422);
}

try {
    $result=api_store_uploaded_file($config,$db,$apiKey,$name,'application/octet-stream',$size,$input,$shareTemplate,$shareLanguage,$maxDownloads,$oneTime,$durationHours);
} finally {
    if (is_resource($input)) fclose($input);
}

json_response([
    'ok'=>true,
    'message'=>'Archivo recibido correctamente.',
    'file'=>[
        'id'=>$result['id'],
        'filename'=>$result['filename'],
        'size'=>$result['size'],
        'sha256'=>$result['sha256'],
        'url'=>$result['url'],
        'pin'=>$result['pin'],
        'expires_at'=>$result['expires_at'],
        'expires_at_local'=>$result['expires_at_local'],
        'share_text'=>$result['share_text'],
        'language'=>$result['language'],
        'downloads'=>$result['downloads'],
        'max_downloads'=>$result['max_downloads'],
        'one_time'=>$result['one_time']
    ]
],201);
