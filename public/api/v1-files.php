<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
require_once dirname(__DIR__,2).'/app/api.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','DELETE'], true)) {
    header('Allow: GET, DELETE');
    json_response(['ok'=>false,'error'=>'method_not_allowed','message'=>'Usa GET o DELETE.'],405);
}

$apiKey=authenticate_api_key($db);
$identifier=trim((string)($_GET['id']??''));
if($identifier==='' || strlen($identifier)>100) json_response(['ok'=>false,'error'=>'invalid_id','message'=>'Identificador inválido.'],422);

$file=api_find_file($db,$identifier,(int)$apiKey['id']);
if(!$file) json_response(['ok'=>false,'error'=>'not_found','message'=>'Archivo no encontrado.'],404);

if($_SERVER['REQUEST_METHOD']==='GET'){
    require_api_scope($apiKey,'files.read',$db,$config);
    $url=rtrim(app_base_url($config),'/').'/f/'.$file['download_id'];
    json_response([
        'ok'=>true,
        'file'=>[
            'id'=>(int)$file['id'],
            'filename'=>$file['original_name'],
            'size'=>(int)$file['file_size'],
            'mime'=>$file['mime_type'],
            'sha256'=>$file['sha256'],
            'url'=>$url,
            'expires_at'=>format_date_iso((string)$file['expires_at']),
            'expires_at_local'=>format_date((string)$file['expires_at']),
            'downloads'=>(int)$file['downloads'],
            'max_downloads'=>(int)$file['max_downloads'],
            'one_time'=>(bool)$file['one_time'],
            'created_at'=>(new DateTimeImmutable($file['created_at']))->format(DateTimeInterface::ATOM)
        ]
    ]);
}

require_api_scope($apiKey,'files.delete',$db,$config);
try {
    delete_file_record($config,$db,$file);
} catch(Throwable $e) {
    audit_event($db,$config,'api_file_delete_failure','ERROR',true,'error','api',(string)$apiKey['id'],'No se pudo eliminar el archivo vía API.',[], 'file',(string)$file['id']);
    json_response(['ok'=>false,'error'=>'delete_failed','message'=>'No se pudo eliminar el archivo físico.'],409);
}
audit_event($db,$config,'api_file_delete','NOTICE',true,'success','api',(string)$apiKey['id'],'Archivo eliminado vía API.',[], 'file',(string)$file['id']);
json_response(['ok'=>true,'message'=>'Archivo eliminado correctamente.','id'=>(int)$file['id']]);
