<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

$branding=branding($db,$config);
$lang=resolve_language($db,$config,null);

audit_event(
    $db,
    $config,
    'http_not_found',
    'INFO',
    true,
    'failure',
    'anonymous',
    null,
    $lang==='en'?'Requested page not found.':'Ruta no encontrada.',
    [],
    'route',
    app_url($config,trim((string)($_SERVER['REQUEST_URI']??''),'/'))
);

$title=$lang==='en'?'Page not found':'Página no encontrada';
$message=$lang==='en'
    ? 'The page you are looking for does not exist or the file is no longer available.'
    : 'La página que buscas no existe o ya no está el archivo disponible.';
$button=$lang==='en'?'Back to home':'Volver al inicio';
$home=app_base_url($config).'/';

http_response_code(404);
?><!doctype html>
<html lang="<?=e($lang)?>">
<head>
<meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<script src="<?=e(app_url($config,'assets/theme.js'))?>"></script>
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
      <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
        <i class="bi bi-circle-half me-1"></i><?=e($lang==='en'?'Theme':'Tema')?>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><button class="dropdown-item theme-choice" data-theme="light"><?=e($lang==='en'?'Light':'Claro')?></button></li>
        <li><button class="dropdown-item theme-choice" data-theme="dark"><?=e($lang==='en'?'Dark':'Oscuro')?></button></li>
        <li><button class="dropdown-item theme-choice" data-theme="auto"><?=e($lang==='en'?'Automatic':'Automático')?></button></li>
      </ul>
    </div>
  </div>
</nav>
<main class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-8 col-lg-7">
      <div class="card border-0 shadow-sm p-4 p-md-5 text-center">
        <div class="hero-icon mx-auto mb-3"><i class="bi bi-compass"></i></div>
        <h1 class="h3"><?=e($title)?></h1>
        <div class="text-body-secondary mb-4"><?=e($message)?></div>
        <a href="<?=e($home)?>" class="btn btn-primary"><i class="bi bi-house me-1"></i><?=e($button)?></a>
      </div>
    </div>
  </div>
</main>
<footer class="text-center text-body-secondary small py-4"><?=e(get_footer_template($db,$config,false))?></footer>
<script src="<?=e(app_url($config,'assets/app.js'))?>"></script>
</body>
</html>
