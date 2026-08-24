<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
send_no_cache_headers();
$branding=branding($db,$config);
$id=trim((string)($_GET['id']??''));$file=find_file_by_download_id($db,$id);$branding=branding($db,$config);$appUrl=app_base_url($config);
if(!$file){ audit_event($db,$config,'file_download_not_found','INFO',true,'failure','anonymous',null,'Enlace de descarga no encontrado.'); http_response_code(404);render_message('Archivo no encontrado','El enlace no existe o el archivo ya fue eliminado.');}
if(is_expired($file)){ audit_event($db,$config,'file_expired_access','INFO',true,'blocked','anonymous',null,'Intento de acceso a archivo expirado.',[],'file',(string)$file['id']); try{delete_file_record($config,$db,$file);}catch(Throwable $e){} render_message('Archivo expirado','Este archivo ya cumplió su periodo de disponibilidad y fue eliminado.');}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$pin=(string)($_POST['pin']??'');$ip=client_ip_address();$rate=failed_auth_window_state($db,'download_pin',(string)$file['download_id'],$ip,5,300);
    if(!$rate['allowed']){
        http_response_code(429);
        audit_event($db,$config,'file_download_pin_throttled','WARNING',true,'blocked','anonymous',null,'Intentos de PIN temporalmente bloqueados.',[],'file',(string)$file['id']);
        $error='Demasiados intentos de PIN. Espera 5 minutos e inténtalo nuevamente.';
    } elseif(!preg_match('/^[0-9]{4}$/D',$pin)||!password_verify($pin,$file['pin_hash'])){
        $state=auth_failed_attempt($db,'download_pin',(string)$file['download_id'],$ip,5,300);
        audit_event($db,$config,'file_download_pin_failure','WARNING',true,'failure','anonymous',null,'PIN incorrecto en descarga.',[],'file',(string)$file['id']);
        $error=$state['count']>=5?'Demasiados intentos de PIN. Espera 5 minutos e inténtalo nuevamente.':'PIN incorrecto. Inténtalo nuevamente.';
        if($state['count']>=5) http_response_code(429);
    } else{
        auth_clear_failed_attempts($db,'download_pin',(string)$file['download_id'],$ip);
        if ((int)$file['max_downloads'] > 0 && (int)$file['downloads'] >= (int)$file['max_downloads']) {
            audit_event($db,$config,'file_download_limit_reached','INFO',true,'blocked','anonymous',null,'Límite de descargas alcanzado.',[],'file',(string)$file['id']);
            render_message('Límite alcanzado','Este enlace ya alcanzó el máximo de descargas permitido.');
        }
        if ((int)$file['one_time'] === 1 && (int)$file['downloads'] >= 1) {
            audit_event($db,$config,'file_download_one_time_used','INFO',true,'blocked','anonymous',null,'Enlace de un solo uso ya consumido.',[],'file',(string)$file['id']);
            render_message('Enlace ya utilizado','Este enlace fue configurado para una sola descarga y ya fue utilizado.');
        }
        $path=safe_file_path($config,$file['stored_name']);if(!is_file($path)){http_response_code(404);render_message('Archivo no disponible','El archivo físico ya no está disponible.');}
        if ((int)filesize($path) !== (int)$file['file_size']) { error_log('Portal download integrity mismatch for file '.$file['id']); http_response_code(500); render_message('Archivo no disponible','El archivo no pudo validarse correctamente.'); }
        audit_event($db,$config,'file_download_success','INFO',true,'success','anonymous',null,'Descarga autorizada.',[],'file',(string)$file['id']); $s=$db->prepare("UPDATE files SET downloads=downloads+1,last_download_at=? WHERE id=? AND (max_downloads=0 OR downloads<max_downloads) AND (one_time=0 OR downloads<1)");
        $s->execute([(new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),$file['id']]);
        if($s->rowCount()!==1){ render_message('Límite alcanzado','Este enlace ya no admite más descargas.'); }
        send_file($path,$file['original_name'],$file['mime_type'],(int)$file['file_size']);}
}
function render_message(string $title,string $message):never{
    global $db,$config,$branding,$appUrl;
    $lang=resolve_language($db,$config,null);
    $themeLabel=$lang==='en'?'Theme':'Tema';
    $lightLabel=$lang==='en'?'Light':'Claro';
    $darkLabel=$lang==='en'?'Dark':'Oscuro';
    $autoLabel=$lang==='en'?'Automatic':'Automático';
    $home=rtrim($appUrl,'/').'/';
    ?>
<!doctype html>
<html lang="<?=e($lang)?>">
<head>
<meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
<script src="<?=e(app_url($config,'assets/theme.js'))?>"></script>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($title)?> · <?=e($branding['name'])?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?=e(app_url($config,'assets/app.css'))?>">
</head>
<body>
<nav class="navbar border-bottom">
  <div class="container py-2">
    <a class="navbar-brand fw-semibold d-flex align-items-center gap-2" href="<?=e($home)?>">
      <?php if($branding['logo']): ?>
        <img src="<?=e(app_url($config,$branding['logo']))?>" class="brand-logo" alt="Logo">
      <?php else: ?>
        <span class="brand-icon"><i class="bi bi-cloud-arrow-up-fill"></i></span>
      <?php endif; ?>
      <span><?=e($branding['name'])?></span>
    </a>
    <div class="dropdown">
      <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-circle-half me-1"></i><?=e($themeLabel)?>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><button type="button" class="dropdown-item theme-choice" data-theme="light"><?=e($lightLabel)?></button></li>
        <li><button type="button" class="dropdown-item theme-choice" data-theme="dark"><?=e($darkLabel)?></button></li>
        <li><button type="button" class="dropdown-item theme-choice" data-theme="auto"><?=e($autoLabel)?></button></li>
      </ul>
    </div>
  </div>
</nav>

<main class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-7 col-lg-6">
      <div class="card border-0 shadow-sm text-center p-4 p-md-5">
        <div class="hero-icon mx-auto mb-3"><i class="bi bi-file-earmark-x"></i></div>
        <h1 class="h3"><?=e($title)?></h1>
        <p class="text-body-secondary mb-4"><?=e($message)?></p>
        <a href="<?=e($home)?>" class="btn btn-primary">
          <i class="bi bi-house me-1"></i><?=e($lang==='en'?'Go to home':'Ir al inicio')?>
        </a>
      </div>
    </div>
  </div>
</main>

<footer class="text-center text-body-secondary small py-4"><?=e(get_footer_template($db,$config,false))?></footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9hJ09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
</body>
</html>
<?php exit;
}

function send_file(string $path,string $name,string $mime,int $size):never{
    while(ob_get_level())ob_end_clean();$name=str_replace(["\r","\n",'"'],'',$name);$fp=fopen($path,'rb');if(!$fp){http_response_code(500);exit;}
    header('Content-Type: '.($mime?:'application/octet-stream'));$fallback = preg_replace('/[^A-Za-z0-9._-]+/u', '_', $name);
    $fallback = trim((string)$fallback, '._');
    if ($fallback === '') {
        $fallback = 'archivo';
    }
    header('Content-Disposition: attachment; filename="' . addcslashes($fallback, '\\"') . '"; filename*=UTF-8\'\'' . rawurlencode($name));header('Accept-Ranges: bytes');header('Cache-Control: private,no-store,no-cache,must-revalidate');header('X-Content-Type-Options: nosniff');header('X-Download-Options: noopen');header('Content-Security-Policy: sandbox; default-src \"none\"');
    $start=0;$end=$size-1;
    if(isset($_SERVER['HTTP_RANGE'])&&preg_match('/bytes=(\d*)-(\d*)/',$_SERVER['HTTP_RANGE'],$m)){ $start=$m[1]!==''?(int)$m[1]:0;$end=$m[2]!==''?(int)$m[2]:$end;if($start>$end||$start>=$size){header('Content-Range: bytes */'.$size);http_response_code(416);exit;}$end=min($end,$size-1);$length=$end-$start+1;http_response_code(206);header("Content-Range: bytes $start-$end/$size");header('Content-Length: '.$length);fseek($fp,$start);$remaining=$length;while($remaining>0&&!feof($fp)){ $b=fread($fp,min(1024*1024,$remaining));if($b===false||$b==='')break;echo $b;$remaining-=strlen($b);flush();}fclose($fp);exit;}
    header('Content-Length: '.$size);while(!feof($fp)){ $b=fread($fp,1024*1024);if($b===false||$b==='')break;echo $b;flush();}fclose($fp);exit;
}
?>
<!doctype html>
<html lang="es"><head><script src="<?=e(app_url($config, "assets/theme.js"))?>"></script><meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Descargar · <?=e($branding['name'])?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"><link rel="stylesheet" href="<?=e(app_url($config, "assets/app.css"))?>"></head>
<body><nav class="navbar border-bottom"><div class="container py-2"><a class="navbar-brand fw-semibold d-flex align-items-center gap-2" href="<?=e($appUrl)?>/"><?php if($branding['logo']): ?><img src="<?=e(app_url($config,$branding['logo']))?>" class="brand-logo" alt="Logo"><?php else: ?><span class="brand-icon"><i class="bi bi-cloud-arrow-up-fill"></i></span><?php endif; ?><span><?=e($branding['name'])?></span></a><div class="dropdown"><button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-circle-half"></i> Tema</button><ul class="dropdown-menu dropdown-menu-end"><li><button class="dropdown-item theme-choice" data-theme="light">Claro</button></li><li><button class="dropdown-item theme-choice" data-theme="dark">Oscuro</button></li><li><button class="dropdown-item theme-choice" data-theme="auto">Automático</button></li></ul></div></div></nav>
<main class="container py-5"><div class="row justify-content-center"><div class="col-md-7 col-lg-6"><div class="card border-0 shadow-sm p-4 p-md-5"><div class="text-center"><div class="hero-icon mx-auto mb-3"><i class="bi bi-file-earmark-arrow-down"></i></div><h1 class="h3">Archivo disponible</h1><p class="text-body-secondary text-break"><?=e($file['original_name'])?></p><div class="small text-body-secondary d-flex justify-content-center align-items-center flex-wrap gap-2"><span><?=e(format_bytes((int)$file['file_size']))?></span><span aria-hidden="true">·</span><span>Expira <?=e(format_date((string)$file['expires_at']))?></span></div><div class="small text-body-secondary text-center mt-1"><i class="bi bi-download"></i> Descargas permitidas: <?=((int)$file['max_downloads']===0 ? '∞' : e((string)$file['max_downloads']))?></div><div class="small text-body-secondary text-center mt-1"><i class="bi bi-bar-chart"></i> Descargas realizadas: <?=number_format((int)$file['downloads'])?></div><div class="mt-3 text-start"><div class="small text-body-secondary mb-1">SHA-256</div><div class="input-group input-group-sm"><input id="downloadSha256" class="form-control font-monospace" value="<?=e($file['sha256'])?>" readonly><button type="button" class="btn btn-outline-secondary" id="copyDownloadSha" title="Copiar SHA-256"><i class="bi bi-copy"></i></button></div></div></div><?php if($error):?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?=e($error)?></div><?php endif;?><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label class="form-label fw-semibold text-center d-block">Introduce el PIN de 4 dígitos</label><input class="form-control form-control-lg text-center font-monospace"
name="pin" id="downloadPin" type="text" inputmode="numeric" pattern="[0-9]{4}"
maxlength="4" minlength="4" autocomplete="one-time-code" required autofocus
aria-describedby="downloadPinHelp"
title="Introduce exactamente 4 dígitos"><button class="btn btn-primary btn-lg w-100 mt-3"><i class="bi bi-download me-1"></i>Descargar archivo</button></form><div class="alert alert-warning mt-4 mb-0 small"><strong>Importante:</strong> el PIN fue generado una sola vez por quien compartió el archivo. No puede volver a consultarse ni regenerarse.</div><div id="downloadPinHelp" class="small text-body-secondary text-center mt-2">Solo números · exactamente 4 dígitos.</div>
</div></div></div></main>
<footer class="text-center text-body-secondary small py-4"><?=e(get_footer_template($db,$config,false))?></footer>
<script nonce="<?=e(csp_nonce())?>">
(() => {
  const pin = document.getElementById('downloadPin');
  if (!pin) return;

  const normalize = () => {
    pin.value = pin.value.replace(/[^0-9]/g, '').slice(0, 4);
  };

  pin.addEventListener('input', normalize);
  pin.addEventListener('beforeinput', (event) => {
    if (event.data && /[^0-9]/.test(event.data)) event.preventDefault();
  });
  pin.addEventListener('keydown', (event) => {
    const allowed = ['Backspace','Delete','Tab','ArrowLeft','ArrowRight','Home','End','Enter'];
    if (allowed.includes(event.key) || event.ctrlKey || event.metaKey) return;
    if (!/^[0-9]$/.test(event.key)) event.preventDefault();
  });
  pin.addEventListener('paste', (event) => {
    event.preventDefault();
    const value = (event.clipboardData || window.clipboardData).getData('text');
    pin.value = value.replace(/[^0-9]/g, '').slice(0, 4);
  });
})();
</script>
</body></html>
