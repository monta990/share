<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
send_no_cache_headers();
cleanup_session_uploads($config);
$branding=branding($db,$config);
$defaultOneTime=((string)setting($db,'default_one_time','0')==='1');
$pageLang=resolve_language($db,$config,null);
$L=[
'upload_title'=>t('home.upload_title',$pageLang),'upload_subtitle'=>t('home.upload_subtitle',$pageLang),
'select'=>t('home.select',$pageLang),'available'=>t('home.available',$pageLang),'hours'=>t('home.hours',$pageLang),
'pin'=>t('home.pin',$pageLang),'storage'=>t('home.storage',$pageLang),'auto_expire'=>t('home.auto_expire',$pageLang),
'pin_required'=>t('home.pin_required',$pageLang)
];
?>
<!doctype html>
<html lang="<?=e($pageLang)?>">
<head>
<script src="<?=e(app_asset_url($config, "assets/theme.js"))?>"></script>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
<meta name="csrf-token" content="<?=e(csrf_token())?>"><meta name="app-api-url" content="<?=e(app_url($config, "api/upload.php"))?>"><meta name="platform-max-file-mb" content="<?=e((string)floor($config['max_file_size']/1048576))?>"><meta name="default-max-downloads" content="<?=e((string)setting($db,'default_max_downloads','0'))?>"><meta name="default-one-time" content="<?=e(setting($db,'default_one_time','0'))?>"><meta name="default-duration-hours" content="<?=e((string)setting($db,'duration_hours',(string)$config['expiration_hours']))?>">
<title><?=e($branding['name'])?> · Compartir archivos</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?=e(app_asset_url($config, "assets/app.css"))?>">
</head>
<body>
<nav class="navbar border-bottom"><div class="container-fluid px-3 px-md-4 px-xxl-5 py-2">
<a class="navbar-brand fw-semibold d-flex align-items-center gap-2" href="<?=e(app_base_url($config))?>/"><?php if($branding['logo']): ?><img src="<?=e(app_asset_url($config,$branding['logo']))?>" class="brand-logo" alt="Logo"><?php else: ?><span class="brand-icon"><i class="bi bi-cloud-arrow-up-fill"></i></span><?php endif; ?><span><?=e($branding['name'])?></span></a>
<div class="dropdown"><button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-circle-half me-1"></i>Tema</button>
<ul class="dropdown-menu dropdown-menu-end"><li><button class="dropdown-item theme-choice" data-theme="light"><i class="bi bi-sun me-2"></i>Claro</button></li><li><button class="dropdown-item theme-choice" data-theme="dark"><i class="bi bi-moon-stars me-2"></i>Oscuro</button></li><li><button class="dropdown-item theme-choice" data-theme="auto"><i class="bi bi-circle-half me-2"></i>Automático</button></li></ul></div></div>
</div></nav>
<main class="public-page py-3"><div class="public-home">
<section class="hero-section text-center mb-3"><div class="hero-icon mx-auto mb-2"><i class="bi bi-cloud-arrow-up"></i></div><h1 class="display-6 fw-bold mb-1"><?=e($branding['name'])?></h1><p class="lead text-body-secondary mb-2"><?=e($branding['tagline'])?></p></section>
<section class="card border-0 shadow-sm upload-card"><div class="card-body p-3 p-md-4"><div id="uploadOptions" class="mb-3">
  <div class="border rounded-3 p-3 p-md-4 upload-options-card">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
      <div>
        <div class="fw-semibold fs-5"><i class="bi bi-sliders me-2" aria-hidden="true"></i><?=e($pageLang==='en'?'Link settings':'Configuración del enlace')?></div>
        <div class="small text-body-secondary"><?=e($pageLang==='en'?'Set these options before selecting or uploading the file.':'Define estas opciones antes de seleccionar o cargar el archivo.')?></div>
      </div>
      <div class="small text-body-secondary text-end">
        <div class="fw-semibold"><?=e($pageLang==='en'?'Default values':'Valores predeterminados')?></div>
        <div><?=e($pageLang==='en'?'These values start from the platform configuration and can be changed here.':'Estos valores parten de la configuración de la plataforma y puedes modificarlos aquí.')?></div>
      </div>
    </div>
    <div class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label fw-semibold" for="durationHours"><?=e($pageLang==='en'?'Link duration':'Duración del enlace')?></label>
        <div class="input-group">
          <input id="durationHours" type="number" class="form-control" min="1" max="8760" inputmode="numeric" value="<?=e((string)setting($db,'duration_hours',(string)$config['expiration_hours']))?>">
          <span class="input-group-text"><?=e($pageLang==='en'?'hours':'horas')?></span>
        </div>
        <div class="small text-body-secondary mt-1"><?=e($pageLang==='en'?'Set how long the download link remains available.':'Define cuánto tiempo permanecerá disponible el enlace de descarga.')?></div>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold" for="maxDownloads"><?=e($pageLang==='en'?'Maximum downloads':'Máximo de descargas')?></label>
        <div id="maxDownloadsGroup" class="input-group<?= $defaultOneTime ? ' is-disabled' : '' ?>">
          <input id="maxDownloads" type="number" class="form-control" min="0" max="1000000" inputmode="numeric" value="<?=e($defaultOneTime ? '1' : (string)setting($db,'default_max_downloads','0'))?>"<?= $defaultOneTime ? ' disabled aria-disabled="true"' : '' ?>>
          <span class="input-group-text"><?=e($pageLang==='en'?'downloads':'descargas')?></span>
        </div>
        <div class="small text-body-secondary mt-1"><?=e($pageLang==='en'?'0 means unlimited':'0 significa ilimitado')?></div>
      </div>
      <div class="col-md-4">
        <div class="form-check form-switch mb-2 d-flex align-items-center gap-2">
          <input class="form-check-input m-0" type="checkbox" id="oneTime"<?= $defaultOneTime ? ' checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="oneTime"><?=e($pageLang==='en'?'One-time link':'Enlace de un solo uso')?></label>
        </div>
        <div class="small text-body-secondary"><?=e($pageLang==='en'?'The link stops accepting downloads after the first successful download.':'El enlace deja de aceptar descargas después de la primera descarga exitosa.')?></div>
      </div>
    </div>
  </div>
</div>

<div id="dropZone" class="drop-zone text-center mt-3" tabindex="0">
  <input id="fileInput" type="file" class="d-none">
  <i class="bi bi-cloud-arrow-up display-5 text-primary"></i>
  <h2 class="h5 mt-2 mb-1"><?=e($L['upload_title'])?></h2>
  <p class="small text-body-secondary mb-2"><?=e($L['upload_subtitle'])?></p>
  <label id="chooseBtn" class="btn btn-primary px-4 mb-0" for="fileInput"><i class="bi bi-folder2-open me-2"></i><?=e($L['select'])?></label>
  <div class="small text-body-secondary mt-1"><?=e($pageLang==='en'?'Maximum per file:':'Máximo por archivo:')?> <strong><?=number_format((int)floor($config['max_file_size']/1048576))?> MB</strong>.</div>
</div>


<div class="d-none">
  <button id="startUploadBtn" class="btn btn-primary" type="button" disabled><i class="bi bi-cloud-arrow-up me-1"></i><?=e($pageLang==='en'?'Upload file':'Subir archivo')?></button>
  <button id="cancelUploadBtn" class="btn btn-outline-secondary" type="button" disabled><i class="bi bi-x-circle me-1"></i><?=e($pageLang==='en'?'Cancel':'Cancelar')?></button>
</div>

<div id="uploadProgress" class="d-none mt-4"><div class="d-flex justify-content-between mb-2"><span id="progressName" class="text-truncate me-3"></span><span id="progressPercent">0%</span></div><div class="progress" style="height:10px"><div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div></div><div id="progressStatus" class="small text-body-secondary mt-2"></div></div>
<div id="result" class="d-none mt-4"></div>
<div id="error" class="d-none mt-4"></div>
</div></section>
<section class="row g-3 mt-2 text-center features-row"><div class="col-md-4"><div class="feature-box"><i class="bi bi-clock-history"></i><strong><?=e((string)setting($db,'duration_hours',(string)$config['expiration_hours']))?> horas</strong><span><?=e($L['auto_expire'])?></span></div></div><div class="col-md-4"><div class="feature-box"><i class="bi bi-shield-lock"></i><strong><?=e($L['pin'])?></strong><span><?=e($L['pin_required'])?></span></div></div><div class="col-md-4"><div class="feature-box"><i class="bi bi-hdd"></i><strong><?=e($L['storage'])?></strong><span>Dentro de tu infraestructura</span></div></div></section>
<footer class="text-center text-body-secondary small mt-3"><?=e(get_footer_template($db,$config,false))?></footer>
</div></main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
<script src="<?=e(app_asset_url($config, "assets/app.js"))?>" defer></script>
<script src="<?=e(app_asset_url($config, "assets/one-time.js"))?>" defer></script>
</body></html>
