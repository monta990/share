<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$logDb=log_db($config);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');


$branding=branding($db,$config);
$allowedAdminTabs=['customization','links','apis','files','security','stats','health','register'];
$uiLanguage=resolve_language($db,$config,null);
$activeTab=(string)($_GET['admin_tab']??($_POST['admin_tab']??'stats'));
if(!in_array($activeTab,$allowedAdminTabs,true)) $activeTab='stats';
$lang=app_language($db,$config);
if (admin_count($db) === 0) {
    $error='';
    if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='setup'){
    try{
        verify_admin_csrf();
        $user=trim((string)($_POST['username']??''));
        $pass=(string)($_POST['password']??'');
        $pass2=(string)($_POST['password2']??'');

        if(!preg_match('/^[A-Za-z0-9._-]{3,40}$/',$user)){
            $error='El usuario debe tener entre 3 y 40 caracteres.';
        }elseif(strlen($pass)<12){
            $error='La contraseña debe tener al menos 12 caracteres.';
        }elseif($pass!==$pass2){
            $error='Las contraseñas no coinciden.';
        }else{
            $dbDir=dirname($config['database_path']);
            if(!is_dir($dbDir) && !@mkdir($dbDir,0770,true) && !is_dir($dbDir)){
                throw new RuntimeException('No se pudo crear la carpeta db.');
            }

            $db->exec("CREATE TABLE IF NOT EXISTS admins(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL,
                last_login_at TEXT NULL
            )");

            $db->beginTransaction();
            $s2=$db->prepare('INSERT INTO admins(username,password_hash,created_at) VALUES(?,?,?)');
            $s2->execute([
                $user,
                password_hash($pass,PASSWORD_DEFAULT),
                (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')
            ]);
            $adminId=(int)$db->lastInsertId();
            $verify=$db->prepare('SELECT id,username FROM admins WHERE id=? LIMIT 1');
            $verify->execute([$adminId]);
            $created=$verify->fetch();
            if(!$created){
                throw new RuntimeException('SQLite no confirmó la creación del administrador.');
            }
            $db->commit();

            audit_event($db,$config,'authn_admin_setup','NOTICE',true,'success','admin',$user,'Administrador inicial creado.');
            session_regenerate_id(true);
            $_SESSION['admin_id']=$adminId;
            $_SESSION['admin_username']=$user;
            $_SESSION['admin_csrf']=bin2hex(random_bytes(32));
            session_write_close();
            header('Location: '.rtrim(app_base_url($config),'/').'/admin',true,303);
            exit;
        }
    }catch(Throwable $e){
        if(isset($db)&&$db instanceof PDO&&$db->inTransaction()){
            try{$db->rollBack();}catch(Throwable $ignored){}
        }
        error_log('Portal admin setup error: '.$e->getMessage());
        $error='No se pudo crear el administrador. '.$e->getMessage();
    }
}
    admin_layout_start('Configuración inicial',$branding,$config);
    ?>
    <div class="auth-page"><div class="auth-shell"><div class="card border-0 shadow-sm"><div class="card-body p-4 p-md-5">
    <div class="text-center mb-4"><i class="bi bi-shield-lock display-5 text-primary"></i><h1 class="h3 mt-3">Crear administrador</h1><p class="text-body-secondary">Esta pantalla aparece solo una vez.</p></div>
    <?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
    <form id="adminSetupForm" method="post" action="<?=e(app_base_url($config))?>/admin" autocomplete="off"><input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>"><input type="hidden" name="action" value="setup"><label class="form-label">Usuario</label><input class="form-control mb-3" name="username" required minlength="3" maxlength="40" autofocus><label class="form-label">Contraseña</label><input type="password" class="form-control mb-3" name="password" required minlength="10"><label class="form-label">Repite la contraseña</label><input type="password" class="form-control mb-4" name="password2" required minlength="12"><button id="setupSubmit" type="submit" class="btn btn-primary w-100"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Crear acceso</button></form><script nonce="<?=e(csp_nonce())?>">
(function(){
  const f=document.getElementById("adminSetupForm"),b=document.getElementById("setupSubmit");
  if(!f||!b)return;
  f.addEventListener("submit",function(){
    if(f.dataset.submitting==="1")return;
    f.dataset.submitting="1";
    b.disabled=true;
    b.innerHTML="<span class=\"spinner-border spinner-border-sm me-1\" aria-hidden=\"true\"></span>Creando acceso…";
    // Native POST continues; the server sends a 303 redirect after commit.
  });
})();
</script>
    </div></div></div></div>
    <?php admin_layout_end(); exit;
}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='logout'){
    verify_admin_csrf();
    audit_event($db,$config,'authn_logout','INFO',true,'success','admin',(string)($_SESSION['admin_username']??''),'Administrador cerró sesión.');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)$params['secure'],
            'httponly' => (bool)$params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
    header('Location: '.app_base_url($config).'/', true, 303);
    exit;
}
if(!admin_logged_in()){
    $error='';
    if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='login'){
        verify_admin_csrf();
        $u=trim((string)($_POST['username']??''));$p=(string)($_POST['password']??'');$ip=client_ip_address();
        $pre=failed_auth_window_state($db,'admin_login',$u,$ip,5,300);
        if(!$pre['allowed']){
            http_response_code(429);
            audit_event($db,$config,'authn_login_throttled','WARNING',true,'blocked','admin',$u,'Inicio de sesión temporalmente bloqueado por demasiados intentos.',[]);
            $error='Demasiados intentos fallidos. Espera 5 minutos e inténtalo nuevamente.';
        } else {
            $s=$db->prepare('SELECT * FROM admins WHERE username=? LIMIT 1');$s->execute([$u]);$a=$s->fetch();
            if($a && password_verify($p,$a['password_hash'])){
                auth_clear_failed_attempts($db,'admin_login',$u,$ip);
                session_regenerate_id(true);$_SESSION['admin_id']=(int)$a['id'];$_SESSION['admin_username']=$a['username'];$_SESSION['admin_csrf']=bin2hex(random_bytes(32));unset($_SESSION['admin_login_failures']);
                audit_event($db,$config,'authn_login_success','INFO',true,'success','admin',(string)$a['username'],'Inicio de sesión administrativo exitoso.');
                $db->prepare('UPDATE admins SET last_login_at=? WHERE id=?')->execute([(new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),$a['id']]);
                session_write_close(); header('Location: '.rtrim(app_base_url($config),'/').'/admin', true, 303); exit;
            }
            $state=auth_failed_attempt($db,'admin_login',$u,$ip,5,300);
            usleep(min(750000,max(150000,$state['count']*150000)));
            audit_event($db,$config,'authn_login_failure','WARNING',true,'failure','admin',$u,'Credenciales administrativas incorrectas.');
            $error=$state['count']>=5 ? 'Demasiados intentos fallidos. Espera 5 minutos e inténtalo nuevamente.' : 'Usuario o contraseña incorrectos.';
            if($state['count']>=5) http_response_code(429);
        }
    }
    admin_layout_start('Acceso administrativo',$branding,$config);
    ?>
    <div class="auth-page"><div class="auth-shell"><div class="card border-0 shadow-sm"><div class="card-body p-4 p-md-5"><div class="text-center mb-4"><i class="bi bi-person-lock display-5 text-primary"></i><h1 class="h3 mt-3">Administración</h1></div><?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?><form method="post" action="<?=e(app_url($config, 'admin'))?>" autocomplete="off"><input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>"><input type="hidden" name="action" value="login"><label class="form-label">Usuario</label><input name="username" class="form-control mb-3" required autofocus><label class="form-label">Contraseña</label><input type="password" name="password" class="form-control mb-4" required><button class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right me-1"></i>Entrar</button></form><div class="text-center mt-3"><a href="<?=e(app_base_url($config))?>/">Volver al portal</a></div></div></div></div></div>
    <?php admin_layout_end(); exit;
}

require_admin_page($db);
$branding=branding($db,$config);
// Ensure legacy SQLite files cannot take the admin panel down during upgrade.
foreach ([
    'files'=>['id','original_name','download_id','stored_name','file_size','expires_at','created_at','downloads'],
    'api_keys'=>['id','name','key_prefix','request_count','last_used_at','revoked_at'],
    'admins'=>['id','username','password_hash','last_login_at']
] as $table=>$requiredColumns) {
    $cols=[]; foreach($db->query('PRAGMA table_info('.$table.')') as $r) $cols[$r['name']]=true;
    $missing=array_values(array_diff($requiredColumns,array_keys($cols)));
    if($missing){ http_response_code(503); throw new RuntimeException('Esquema SQLite incompleto en '.$table.': '.implode(', ',$missing)); }
}

$action=$_POST['action']??'';
$notice=(string)($_SESSION['admin_notice']??'');
$error=(string)($_SESSION['admin_error']??'');
unset($_SESSION['admin_notice'],$_SESSION['admin_error']);
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_admin_csrf();

    if($action==='unlock_file_pin'){
        $downloadId=trim((string)($_POST['download_id']??''));
        if($downloadId===''){
            $error='Identificador de enlace no válido.';
            $tab='files';
        } else {
            try{
                $subjectHash=auth_subject_hash($downloadId);
                $stUnlock=$db->prepare("DELETE FROM auth_failed_attempts WHERE scope='download_pin' AND subject_hash=?");
                $stUnlock->execute([$subjectHash]);
                $removed=$stUnlock->rowCount();
                audit_event(
                    $db,$config,'admin_unlock_download_pin','NOTICE',true,'success','admin',
                    null,'Administrador desbloqueó los intentos del PIN de un enlace.',
                    ['download_id'=>$downloadId,'records_removed'=>$removed],
                    'file',$downloadId
                );
                $notice=$removed>0
                    ? 'Bloqueo del PIN eliminado. El enlace vuelve a aceptar intentos.'
                    : 'No había un bloqueo activo para este enlace.';
                $tab='files';
            }catch(Throwable $e){
                $error='No se pudo desbloquear el PIN del enlace.';
                $tab='files';
            }
        }
    }

    if($action==='scan_existing_file'){
        $id=(int)($_POST['id']??0);
        $tab='files';
        if($id<1){
            $error='Archivo no válido.';
        }elseif(!antivirus_enabled($db)){
            $error='El antivirus no está activo. Actívalo y verifica el comando desde Seguridad antes de analizar archivos existentes.';
        }else{
            try{
                $st=$db->prepare('SELECT * FROM files WHERE id=? LIMIT 1');
                $st->execute([$id]);
                $file=$st->fetch();
                if(!$file){
                    $error='Archivo no encontrado.';
                }elseif((int)($file['antivirus_scanned']??0)===1){
                    $notice='Este archivo ya fue analizado y resultó limpio.';
                }else{
                    $path=safe_file_path($config,$file['stored_name']);
                    if(!is_file($path) || !is_readable($path)){
                        $error='No se pudo acceder al archivo físico para analizarlo.';
                        audit_event($db,$config,'file_antivirus_rescan','ERROR',true,'error','admin',null,
                            'No se pudo analizar un archivo existente porque el archivo físico no está disponible.',
                            ['file_id'=>$id]);
                    }else{
                        $scan=run_antivirus_scan($db,$config,$path);
                        if($scan['status']==='clean'){
                            $up=$db->prepare('UPDATE files SET antivirus_scanned=1 WHERE id=?');
                            $up->execute([$id]);
                            audit_event($db,$config,'file_antivirus_rescan','INFO',true,'success','admin',null,
                                'Un archivo existente superó correctamente el análisis antivirus.',
                                ['file_id'=>$id,'status'=>'clean']);
                            $notice='Archivo analizado correctamente. El antivirus no detectó amenazas.';
                        }elseif($scan['status']==='infected'){
                            delete_file_record($config,$db,$file);
                            audit_event($db,$config,'file_antivirus_rescan','WARNING',true,'failure','admin',null,
                                'El antivirus detectó una amenaza en un archivo existente y el archivo fue rechazado y eliminado.',
                                ['file_id'=>$id,'status'=>'infected']);
                            $error='Amenaza detectada. El archivo y su enlace fueron rechazados y eliminados.';
                        }else{
                            audit_event($db,$config,'file_antivirus_rescan','ERROR',true,'error','admin',null,
                                'No se pudo determinar el resultado del análisis antivirus de un archivo existente.',
                                ['file_id'=>$id,'status'=>$scan['status']]);
                            $error='No se pudo determinar el resultado del antivirus. El archivo no fue modificado ni eliminado.';
                        }
                    }
                }
            }catch(Throwable $e){
                $error='No se pudo analizar el archivo.';
                audit_event($db,$config,'file_antivirus_rescan_failure','ERROR',true,'error','admin',null,
                    'Falló el análisis antivirus manual de un archivo existente.',
                    ['file_id'=>$id]);
            }
        }
    }

    if($action==='clear_all_logs'){
        try{
            $logDb->beginTransaction();
            $logDb->exec('DELETE FROM audit_logs');
            $logDb->exec("UPDATE audit_meta SET value='".str_repeat('0',64)."' WHERE key='chain_anchor'");
            $logDb->exec("INSERT OR IGNORE INTO audit_meta(key,value) VALUES('chain_anchor','".str_repeat('0',64)."')");
            $logDb->commit();
            $_SESSION['admin_notice']='Todos los registros de auditoría fueron eliminados.';
            $_SESSION['audit_skip_view_once']=true;
            header('Location: '.rtrim(app_base_url($config),'/').'/admin?admin_tab=register',true,303);
            exit;
        }catch(Throwable $e){
            if($logDb->inTransaction()) $logDb->rollBack();
            error_log('Portal clear audit logs failed: '.$e->getMessage());
            $_SESSION['admin_error']='No se pudieron eliminar los registros de auditoría.';
            header('Location: '.rtrim(app_base_url($config),'/').'/admin?admin_tab=register',true,303);
            exit;
        }
    }

    
if($action==='run_cleanup_manual'){
    try{
        require_once dirname(__DIR__).'/cron/cleanup.php';
        $result=execute_cleanup_from_cron($db,$config,'admin','manual');
        $tab='health';
        if($result['status']==='success'){
            $notice='Limpieza ejecutada correctamente: '.$result['deleted'].' archivo(s) eliminado(s) en '.$result['duration_ms'].' ms.';
        }else{
            $error='La limpieza terminó con '.count($result['errors']).' error(es). Revisa el Registro para ver el detalle.';
        }
    }catch(Throwable $e){
        $tab='health';
        $error='No se pudo ejecutar la limpieza: '.$e->getMessage();
    }
}

if($action==='save_antivirus_settings'){
    verify_admin_csrf();
    $enabled=isset($_POST['antivirus_enabled']) && (string)$_POST['antivirus_enabled']==='1';
    $command=trim((string)($_POST['antivirus_command']??''));
    try{
        $command=validate_antivirus_command_template($command);
        set_setting($db,'antivirus_enabled',$enabled?'1':'0');
        set_setting($db,'antivirus_command',$command);
        $config['antivirus_enabled']=$enabled;
        $config['antivirus_command']=$command;
        $test=$enabled?test_antivirus_configuration($db,$config):['status'=>'disabled','message'=>'El análisis antivirus quedó desactivado.','detail'=>''];
        if($enabled && $test['status']!=='clean'){
            set_setting($db,'antivirus_enabled','0');
            $config['antivirus_enabled']=false;
            $error='La configuración del comando se guardó, pero el antivirus quedó desactivado porque la prueba no fue satisfactoria: '.$test['message'];
            audit_event($db,$config,'admin_antivirus_config_change','WARNING',true,'warning','admin',null,
                'Administrador actualizó la configuración del antivirus, pero la prueba no fue satisfactoria.',
                ['enabled'=>$enabled,'test_status'=>$test['status']]);
        }else{
            $notice=$enabled?'Antivirus configurado y verificado correctamente.':'Análisis antivirus desactivado.';
            audit_event($db,$config,'admin_antivirus_config_change','NOTICE',true,'success','admin',null,
                $enabled?'Administrador configuró y verificó el antivirus.':'Administrador desactivó el análisis antivirus.',
                ['enabled'=>$enabled,'test_status'=>$test['status']]);
        }
    }catch(Throwable $e){
        $error=$e->getMessage();
        audit_event($db,$config,'admin_antivirus_config_failure','ERROR',true,'error','admin',null,
            'Falló la actualización de la configuración del antivirus.');
    }
    $tab='security';
}

if($action==='test_antivirus'){
    verify_admin_csrf();
    try{
        $test=test_antivirus_configuration($db,$config);
        if($test['status']==='clean'){
            $notice='Antivirus disponible y funcionando. Resultado de prueba: limpio.';
            audit_event($db,$config,'admin_antivirus_test','INFO',true,'success','admin',null,
                'Prueba manual del antivirus completada correctamente.',
                ['status'=>$test['status']]);
        }elseif($test['status']==='disabled'){
            $error='El análisis antivirus está desactivado. Actívalo para realizar una prueba.';
        }else{
            $error='La prueba del antivirus no fue satisfactoria: '.$test['message'];
            audit_event($db,$config,'admin_antivirus_test','WARNING',true,'failure','admin',null,
                'La prueba manual del antivirus no fue satisfactoria.',
                ['status'=>$test['status']]);
        }
    }catch(Throwable $e){
        $error='No se pudo probar el antivirus.';
        audit_event($db,$config,'admin_antivirus_test_failure','ERROR',true,'error','admin',null,
            'Falló la prueba manual del antivirus.');
    }
    $tab='security';
}

if($action==='save_maintenance_mode'){
    verify_admin_csrf();
    $submittedState=(string)($_POST['maintenance_mode']??'');
    if($submittedState!=='0' && $submittedState!=='1'){
        $error='Estado de mantenimiento no válido.';
        $tab='security';
    } else {
        $enabled=$submittedState==='1';
    set_setting($db,'maintenance_mode',$enabled?'1':'0');
    audit_event(
        $db,
        $config,
        'admin_maintenance_mode',
        'NOTICE',
        true,
        'success',
        'admin',
        null,
        $enabled?'Modo mantenimiento activado.':'Modo mantenimiento desactivado.',
        ['enabled'=>$enabled]
    );
        $notice=$enabled?'Modo mantenimiento activado. El sitio público y las APIs responderán con mantenimiento; Administración seguirá disponible.':'Modo mantenimiento desactivado. El sitio público y las APIs vuelven a estar disponibles.';
        $tab='security';
    }
}


if($action==='save_php_runtime_limits'){
    verify_admin_csrf();
    $execution=filter_var($_POST['php_max_execution_time']??null,FILTER_VALIDATE_INT);
    $inputTime=filter_var($_POST['php_max_input_time']??null,FILTER_VALIDATE_INT);
    if($execution===false || $execution<30 || $execution>3600){
        $error='El tiempo máximo de ejecución debe ser un entero entre 30 y 3600 segundos.';
    } elseif($inputTime===false || $inputTime<30 || $inputTime>3600){
        $error='El tiempo máximo de entrada debe ser un entero entre 30 y 3600 segundos.';
    } else {
        try{
            set_setting($db,'php_max_execution_time',(string)$execution);
            set_setting($db,'php_max_input_time',(string)$inputTime);
            $config['php_max_execution_time']=$execution;
            $config['php_max_input_time']=$inputTime;
            $maxMb=max(1,(int)floor(platform_max_file_size_bytes($db,$config)/1048576));
            sync_php_upload_ini($config,$maxMb);
            $notice="Límites de PHP actualizados: ejecución {$execution}s y entrada {$inputTime}s.";
            audit_event($db,$config,'admin_php_runtime_change','NOTICE',true,'success','admin',null,
                'Administrador actualizó los límites de tiempo de ejecución de PHP.',
                ['max_execution_time'=>$execution,'max_input_time'=>$inputTime]);
        }catch(Throwable $e){
            $error='No se pudieron actualizar los límites de PHP. Verifica permisos de escritura sobre public/php.ini y public/.user.ini.';
            audit_event($db,$config,'admin_php_runtime_change_failure','ERROR',true,'error','admin',null,
                'Falló la actualización de los límites de tiempo de PHP.');
        }
    }
    $tab='links';
}

if($action==='save_timezone'){
    verify_admin_csrf();
    $timezone=trim((string)($_POST['timezone']??''));
    $zones=DateTimeZone::listIdentifiers();
    if($timezone==='' || !in_array($timezone,$zones,true)){
        $error='La zona horaria seleccionada no es válida para esta versión de PHP.';
    } else {
        try{
            set_setting($db,'timezone',$timezone);
            $config['timezone']=$timezone;
            date_default_timezone_set($timezone);
            sync_php_upload_ini($config,(int)floor($config['max_file_size']/1048576));
            $notice='Zona horaria actualizada a '.$timezone.'. También se actualizó public/php.ini.';
            audit_event($db,$config,'admin_timezone_change','NOTICE',true,'success','admin',null,'Administrador actualizó la zona horaria.',[
                'timezone'=>$timezone
            ]);
        }catch(Throwable $e){
            $error=$e->getMessage();
        }
    }
    $tab='links';
}


if($action==='save_upload_rate_limit'){
    verify_admin_csrf();
    $rate = filter_input(INPUT_POST, 'upload_rate_limit_per_hour', FILTER_VALIDATE_INT);
    if($rate === false || $rate === null || $rate < 0 || $rate > 1000000){
        $error='La cantidad de cargas por IP debe ser un entero entre 0 y 1,000,000. 0 significa ilimitado.';
    } else {
        set_setting($db,'upload_rate_limit_per_hour',(string)$rate);
        $notice = 'Límite de cargas por IP guardado correctamente.';
        audit_event($db,$config,'admin_upload_rate_limit_change','NOTICE',true,'success','admin',null,
            'Administrador actualizó el límite de cargas por IP.', ['uploads_per_hour'=>$rate]);
    }
    $tab='links';
}


if($action==='save_download_policy'){
    verify_admin_csrf();
    $max=filter_input(INPUT_POST,'default_max_downloads',FILTER_VALIDATE_INT);
    $one=filter_input(INPUT_POST,'default_one_time',FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
    if($max===false||$max===null||$max<0||$max>1000000){
        $error='El máximo global de descargas debe ser un entero entre 0 y 1,000,000.';
    } else {
        $one=(bool)$one;
        if($one)$max=1;
        set_setting($db,'default_max_downloads',(string)$max);
        set_setting($db,'default_one_time',$one?'1':'0');
        $notice='Política de descargas guardada.';
        audit_event($db,$config,'admin_download_policy_change','NOTICE',true,'success','admin',null,
            'Administrador actualizó la política global de descargas.',
            ['default_max_downloads'=>$max,'default_one_time'=>$one]);
    }
    $tab='links';
}


if($action==='optimize_databases'){
    try{
        $optimizationResults=optimize_sqlite_databases($config);
        $allOk=($optimizationResults['main']['ok']??false) && ($optimizationResults['logs']['ok']??false);
        if($allOk){
            $notice='Las bases SQLite se optimizaron y verificaron correctamente.';
        } else {
            $error='La optimización terminó, pero una de las comprobaciones de integridad requiere atención.';
        }
        audit_event($db,$config,'admin_database_optimize','NOTICE',true,$allOk?'success':'warning','admin',null,
            'Optimización manual de las bases SQLite ejecutada.',
            ['main'=>$optimizationResults['main'],'logs'=>$optimizationResults['logs']]);
    }catch(Throwable $e){
        $error='No se pudo optimizar las bases SQLite. Verifica espacio disponible y que no haya otra operación SQLite ejecutándose.';
        audit_event($db,$config,'admin_database_optimize_failure','ERROR',true,'error','admin',null,
            'Falló la optimización manual de las bases SQLite.');
    }
    $tab='health';
}

if($action==='save_max_file_size'){
        verify_admin_csrf();
        $mb=filter_var($_POST['max_file_size_mb']??null,FILTER_VALIDATE_INT);
        if($mb===false || $mb<1 || $mb>1048576){
            $error='El límite de carga debe ser un número entero entre 1 y 1,048,576 MB.';
        } else {
            try{
                set_setting($db,'max_file_size_mb',(string)$mb);
                $config['max_file_size']=$mb*1048576;
                sync_php_upload_ini($config,$mb);
                $notice='Límite máximo de archivos actualizado a '.number_format($mb).' MB ('.format_bytes($mb*1048576).'). También se actualizó public/php.ini.';
                audit_event($db,$config,'admin_upload_limit_change','NOTICE',true,'success','admin',null,'Administrador actualizó el límite máximo de carga.',[
                    'max_file_size_mb'=>$mb,
                    'php_ini_upload_max'=>php_ini_size_for_mb($mb),
                    'php_ini_post_max'=>php_ini_size_for_mb(min(1048576,$mb+16))
                ]);
            }catch(Throwable $e){
                $error='No se pudo actualizar el límite máximo de carga. Verifica permisos de escritura sobre public/php.ini y public/.user.ini.';
                audit_event($db,$config,'admin_upload_limit_change_failure','ERROR',true,'error','admin',null,
                    'Falló la actualización del límite máximo de carga.');
            }
        }
    } elseif($action==='save_duration'){
        verify_admin_csrf();
        $hours=filter_var($_POST['duration_hours']??null,FILTER_VALIDATE_INT);
        if($hours===false || $hours<1 || $hours>8760){
            $error='La duración debe ser un número entero entre 1 y 8760 horas.';
        } else {
            $now=(new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $db->beginTransaction();
            try {
                set_setting($db,'duration_hours',(string)$hours);
                // Retroactivo: solo archivos todavía vigentes. Se calcula desde su creación.
                $st=$db->prepare("UPDATE files SET expires_at=datetime(created_at, '+' || ? || ' hours') WHERE expires_at > ?");
                $st->execute([$hours,$now]);
                $db->commit();
                $affected=$st->rowCount();
                $notice="Duración global actualizada a {$hours} horas. {$affected} enlace(s) vigente(s) fueron ajustados retroactivamente.";
                audit_event($db,$config,'admin_duration_change','NOTICE',true,'success','admin',null,
                    'Administrador actualizó la duración global de los enlaces.',
                    ['duration_hours'=>$hours,'affected_links'=>$affected]);
            } catch(Throwable $e){
                if($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }
    } elseif($action==='save_share_templates'){
        $templates = [
            'es' => trim((string)($_POST['share_template_es']??'')),
            'en' => trim((string)($_POST['share_template_en']??'')),
        ];
        foreach($templates as $language=>$template){
            if($template==='' || mb_strlen($template)>5000){
                $error='La plantilla '.($language==='en'?'English':'Español').' no puede estar vacía ni superar 5000 caracteres.';
                break;
            }
            $v=validate_share_template($template);
            if(!$v['ok']){
                $parts=[];
                if($v['missing']) $parts[]='Faltan: '.implode(', ',$v['missing']);
                if($v['unknown']) $parts[]='No válidos: '.implode(', ',$v['unknown']);
                $error='La plantilla '.($language==='en'?'English':'Español').' no es válida. '.implode(' | ',$parts);
                break;
            }
        }
        if(!$error){
            set_setting($db,'share_template_es',$templates['es']);
            set_setting($db,'share_template_en',$templates['en']);
            audit_event($db,$config,'admin_config_change','NOTICE',true,'success','admin',null,'Plantillas para compartir actualizadas.', [], 'setting','share_templates');
            $notice='Plantillas para compartir guardadas correctamente.';
        }
    } elseif($action==='save_branding'){
        $name=trim((string)($_POST['app_name']??'')); $tag=trim((string)($_POST['app_tagline']??''));
        if($name==='')$name='Portal de archivos';
        set_setting($db,'app_name',mb_substr($name,0,80)); set_setting($db,'app_tagline',mb_substr($tag,0,180)); audit_event($db,$config,'admin_config_change','NOTICE',true,'success','admin',null,'Marca del portal actualizada.', [], 'setting','branding'); $notice='Marca actualizada.';
    } elseif($action==='upload_logo'&&isset($_FILES['logo'])){
        $f=$_FILES['logo'];
        if($f['error']!==UPLOAD_ERR_OK)$error='No se pudo recibir el logo.';
        elseif($f['size']>2*1024*1024)$error='El logo no puede superar 2 MB.';
        else{$fi=new finfo(FILEINFO_MIME_TYPE);$mime=$fi->file($f['tmp_name']);$ext=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime]??null;
            if(!$ext)$error='Formato no permitido. Usa PNG, JPG o WEBP.'; else{ if (@getimagesize($f['tmp_name']) === false) { $error='El archivo de logo no es una imagen válida.'; } else { $dir=dirname(__DIR__).'/public/assets/branding';if(!is_dir($dir))mkdir($dir,0775,true);foreach(glob($dir.'/logo.*')?:[] as $old)@unlink($old);$dest=$dir.'/logo.'.$ext;
                if(move_uploaded_file($f['tmp_name'],$dest)){set_setting($db,'logo_path','assets/branding/logo.'.$ext);set_setting($db,'logo_version',bin2hex(random_bytes(12)));audit_event($db,$config,'admin_config_change','NOTICE',true,'success','admin',null,'Logo del portal actualizado.', [], 'setting','logo'); $notice='Logo actualizado.';}else$error='No se pudo guardar el logo.';
            }}
        }
    } elseif($action==='remove_logo'){
        set_setting($db,'logo_path',''); set_setting($db,'logo_version',''); foreach(glob(dirname(__DIR__).'/assets/branding/logo.*')?:[] as $old)@unlink($old); audit_event($db,$config,'admin_config_change','NOTICE',true,'success','admin',null,'Logo del portal eliminado.', [], 'setting','logo'); $notice='Logo eliminado.';
    } elseif($action==='delete_file'){
        $id=(int)($_POST['id']??0);$st=$db->prepare('SELECT * FROM files WHERE id=?');$st->execute([$id]);
        if($f=$st->fetch()){delete_file_record($config,$db,$f);audit_event($db,$config,'admin_file_delete','NOTICE',true,'success','admin',null,'Archivo eliminado manualmente.', [], 'file',(string)$id); $notice='Archivo eliminado inmediatamente.';}
    } elseif($action==='delete_expired'){
        $expired=$db->query("SELECT * FROM files WHERE expires_at<=datetime('now')")->fetchAll(); $n=0;
        foreach($expired as $f){delete_file_record($config,$db,$f);$n++;} $notice=$n?"Se eliminaron {$n} archivos expirados.":'No había archivos expirados para eliminar.'; audit_event($db,$config,'admin_file_cleanup','NOTICE',true,'success','admin',null,'Limpieza manual de archivos expirados.', ['deleted'=>$n]);
    } else
if($action==='update_api_rate_limit'){
    verify_admin_csrf();
    $id=(int)($_POST['api_key_id']??0);
    $limit=filter_input(INPUT_POST,'requests_per_hour',FILTER_VALIDATE_INT);
    if($id<1 || $limit===false || $limit===null || $limit<0 || $limit>1000000){
        $error='Límite de secret inválido.';
    } else {
        $stmt=$db->prepare('UPDATE api_keys SET requests_per_hour=? WHERE id=?');
        $stmt->execute([$limit,$id]);
        $notice='Límite del secret actualizado.';
    }
    $tab='apis';
}


if($action==='update_api_policy'){
    $id=(int)($_POST['api_key_id']??0);
    $limit=filter_input(INPUT_POST,'requests_per_hour',FILTER_VALIDATE_INT);
    $quotaFiles=filter_input(INPUT_POST,'quota_files_per_day',FILTER_VALIDATE_INT);
    $quotaBytesGb=filter_input(INPUT_POST,'quota_gb_per_day',FILTER_VALIDATE_INT);
    $scopes=array_values(array_intersect(api_scopes_all(), array_map('strval', $_POST['scopes'] ?? [])));
    if(!in_array('files.upload',$scopes,true) && !in_array('files.read',$scopes,true) && !in_array('files.delete',$scopes,true)) $error='Selecciona al menos un scope.';
    elseif($limit===false||$limit===null||$limit<0||$limit>1000000) $error='Límite por hora inválido.';
    elseif($quotaFiles===false||$quotaFiles===null||$quotaFiles<0||$quotaFiles>1000000) $error='Cuota diaria de archivos inválida.';
    elseif($quotaBytesGb===false||$quotaBytesGb===null||$quotaBytesGb<0||$quotaBytesGb>1000000) $error='Cuota diaria de GB inválida.';
    else{
        $quotaBytes=(int)$quotaBytesGb*1024*1024*1024;
        $st=$db->prepare('UPDATE api_keys SET requests_per_hour=?,quota_files_per_day=?,quota_bytes_per_day=?,scopes_json=? WHERE id=?');
        $st->execute([$limit,$quotaFiles,$quotaBytes,json_encode($scopes,JSON_UNESCAPED_SLASHES),$id]);
        audit_event($db,$config,'admin_api_policy_change','NOTICE',true,'success','admin',null,'Política de API actualizada.',[], 'api_key',(string)$id);
        $notice='Política del secret actualizada.';
    }
    $tab='apis';
}

if($action==='create_api_key') {
    $name=trim((string)($_POST['key_name']??''));
    $requestsPerHour=filter_input(INPUT_POST,'requests_per_hour',FILTER_VALIDATE_INT);
    $quotaFiles=filter_input(INPUT_POST,'quota_files_per_day',FILTER_VALIDATE_INT);
    $quotaGb=filter_input(INPUT_POST,'quota_gb_per_day',FILTER_VALIDATE_INT);
    $scopes=array_values(array_intersect(api_scopes_all(), array_map('strval', $_POST['scopes'] ?? [])));

    if($name===''||mb_strlen($name)>80) $error='Nombre de secret inválido.';
    elseif(!in_array('files.upload',$scopes,true) && !in_array('files.read',$scopes,true) && !in_array('files.delete',$scopes,true)) $error='Selecciona al menos un scope.';
    elseif($requestsPerHour===false||$requestsPerHour===null||$requestsPerHour<0||$requestsPerHour>1000000) $error='Límite por hora inválido.';
    elseif($quotaFiles===false||$quotaFiles===null||$quotaFiles<0||$quotaFiles>1000000) $error='Cuota diaria de archivos inválida.';
    elseif($quotaGb===false||$quotaGb===null||$quotaGb<0||$quotaGb>1000000) $error='Cuota diaria de GB inválida.';
    else{
        $plain='pf_'.bin2hex(random_bytes(32));
        $prefix=substr($plain,0,14);
        $hash=hash('sha256',$plain);
        $now=(new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $quotaBytes=(int)$quotaGb*1024*1024*1024;
        $st=$db->prepare('INSERT INTO api_keys(name,key_prefix,key_hash,created_at,requests_per_hour,scopes_json,quota_files_per_day,quota_bytes_per_day) VALUES(?,?,?,?,?,?,?,?)');
        $st->execute([$name,$prefix,$hash,$now,$requestsPerHour,json_encode($scopes,JSON_UNESCAPED_SLASHES),$quotaFiles,$quotaBytes]);
        $_SESSION['new_api_key']=$plain;
        audit_event($db,$config,'admin_api_key_create','NOTICE',true,'success','admin',null,'Secret API creado.',[], 'api_key',(string)$db->lastInsertId());
        $notice='Secret creado correctamente. Cópialo ahora; no volverá a mostrarse.';
        $tab='apis';
    }
    } elseif($action==='revoke_api_key') {
        $id=(int)($_POST['api_key_id']??0);
        $st=$db->prepare('UPDATE api_keys SET revoked_at=? WHERE id=? AND revoked_at IS NULL');
        $st->execute([(new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),$id]);
        if($st->rowCount()) audit_event($db,$config,'admin_api_key_revoke','NOTICE',true,'success','admin',null,'Secret de API revocado.', [], 'api_key',(string)$id); $notice=$st->rowCount()?'Secret revocado.':'El secret ya estaba revocado o no existe.';
    } elseif($action==='delete_api_key') {
        $id=(int)($_POST['api_key_id']??0);
        $st=$db->prepare('DELETE FROM api_keys WHERE id=?');
        $st->execute([$id]);
        if($st->rowCount()) audit_event($db,$config,'admin_api_key_delete','NOTICE',true,'success','admin',null,'Secret de API eliminado.', [], 'api_key',(string)$id); $notice=$st->rowCount()?'Secret eliminado permanentemente.':'El secret no existe.';
    } elseif($action==='save_footer_template') {
        $template=trim((string)($_POST['footer_template']??''));
        if($template==='' || mb_strlen($template)>1000) $error='La plantilla del pie de página es obligatoria y no puede superar 1000 caracteres.';
        else {
            $v=validate_footer_template($template);
            if(!$v['ok']) $error='La plantilla del pie de página contiene tags no válidos: '.implode(', ',$v['unknown']);
            else { set_setting($db,'footer_template',$template); audit_event($db,$config,'admin_config_change','NOTICE',true,'success','admin',null,'Pie de página actualizado.', [], 'setting','footer_template'); $notice='Pie de página actualizado.'; }
        }
    } elseif($action==='change_password'){
        $current=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');$new2=(string)($_POST['new_password2']??'');$st=$db->prepare('SELECT * FROM admins WHERE id=?');$st->execute([$_SESSION['admin_id']]);$a=$st->fetch();
        if(!$a||!password_verify($current,$a['password_hash']))$error='La contraseña actual no es correcta.';elseif(strlen($new)<12)$error='La nueva contraseña debe tener al menos 12 caracteres.';elseif($new!==$new2)$error='Las contraseñas no coinciden.';else{$db->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$a['id']]);audit_event($db,$config,'admin_password_change','NOTICE',true,'success','admin',(string)$_SESSION['admin_username'],'Contraseña administrativa actualizada.'); $notice='Contraseña actualizada.';}
    }
}
$branding=branding($db,$config);
$total=(int)$db->query('SELECT COUNT(*) FROM files')->fetchColumn();
$active=(int)$db->query("SELECT COUNT(*) FROM files WHERE expires_at>datetime('now')")->fetchColumn();
$expired=(int)$db->query("SELECT COUNT(*) FROM files WHERE expires_at<=datetime('now')")->fetchColumn();
$downloads=(int)$db->query('SELECT COALESCE(SUM(downloads),0) FROM files')->fetchColumn();
$bytes=(int)$db->query('SELECT COALESCE(SUM(file_size),0) FROM files')->fetchColumn();
$activeBytes=(int)$db->query("SELECT COALESCE(SUM(file_size),0) FROM files WHERE expires_at>datetime('now')")->fetchColumn();
$physicalBytes=0;
foreach(glob($config['storage_path'].'/files/*')?:[] as $fp){if(is_file($fp))$physicalBytes+=(int)filesize($fp);}
$q=trim((string)($_GET['q']??''));$status=(string)($_GET['status']??'active');
$where=[];$params=[];
if($status==='active')$where[]="expires_at>datetime('now')";elseif($status==='expired')$where[]="expires_at<=datetime('now')";
if($q!==''){$where[]='(original_name LIKE ? OR download_id LIKE ?)';$like='%'.$q.'%';$params=[$like,$like];}
$sql='SELECT * FROM files'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY created_at DESC LIMIT 500';
$st=$db->prepare($sql);$st->execute($params);$files=$st->fetchAll();

// Seguridad de PIN: se muestran los bloqueos actuales por enlace sin almacenar ni mostrar el PIN.
$pinSecurity=[];
$pinWindowStart=intdiv(time(),300)*300;
try{
    $authCols=[];
    foreach($db->query("PRAGMA table_info(auth_failed_attempts)") as $r){$authCols[$r['name']]=true;}
    if($authCols){
        $secSt=$db->prepare("SELECT subject_hash, COUNT(*) AS ip_count, COALESCE(SUM(attempt_count),0) AS total_attempts,
                                    MAX(attempt_count) AS max_attempts
                             FROM auth_failed_attempts
                             WHERE scope='download_pin' AND window_start=?
                             GROUP BY subject_hash");
        $secSt->execute([$pinWindowStart]);
        foreach($secSt->fetchAll() as $secRow){
            $pinSecurity[(string)$secRow['subject_hash']]=[
                'ip_count'=>(int)$secRow['ip_count'],
                'total_attempts'=>(int)$secRow['total_attempts'],
                'max_attempts'=>(int)$secRow['max_attempts'],
                'blocked'=>((int)$secRow['max_attempts']>=5),
            ];
        }
    }
}catch(Throwable $e){
    $pinSecurity=[];
}

$base=app_base_url($config);
$durationHours=max(1,(int)setting($db,'duration_hours',(string)$config['expiration_hours']));
$shareTemplateEs=get_share_template($db,'es'); $shareTemplateEn=get_share_template($db,'en');
$statsTodayUploads=(int)$db->query("SELECT COUNT(*) FROM files WHERE created_at>=datetime('now','-1 day')")->fetchColumn();
$statsWeekUploads=(int)$db->query("SELECT COUNT(*) FROM files WHERE created_at>=datetime('now','-7 day')")->fetchColumn();
$statsTodayDownloads=(int)$db->query("SELECT COALESCE(SUM(downloads),0) FROM files WHERE last_download_at>=datetime('now','-1 day')")->fetchColumn();
$statsWeekDownloads=(int)$db->query("SELECT COALESCE(SUM(downloads),0) FROM files WHERE last_download_at>=datetime('now','-7 day')")->fetchColumn();
$statsTodayBytes=(int)$db->query("SELECT COALESCE(SUM(file_size),0) FROM files WHERE created_at>=datetime('now','-1 day')")->fetchColumn();
$statsWeekBytes=(int)$db->query("SELECT COALESCE(SUM(file_size),0) FROM files WHERE created_at>=datetime('now','-7 day')")->fetchColumn();
$statsApiTop=$db->query("SELECT name,request_count,revoked_at FROM api_keys ORDER BY request_count DESC LIMIT 10")->fetchAll();

$phpTimezones=DateTimeZone::listIdentifiers();
$selectedTimezone=(string)setting($db,'timezone',(string)$config['timezone']);

// Health checks
$health = [];
$health['php'] = version_compare(PHP_VERSION,'8.2.0','>=');
$health['pdo_sqlite'] = extension_loaded('pdo_sqlite') && extension_loaded('sqlite3');
$health['storage'] = is_dir($config['storage_path']) && is_writable($config['storage_path']);
$health['database'] = is_dir(dirname($config['database_path'])) && is_writable(dirname($config['database_path']));
$health['logs'] = is_dir(dirname($config['logs_database_path'])) && is_writable(dirname($config['logs_database_path']));
$platformMaxBytes=platform_max_file_size_bytes($db,$config);
$platformMaxMb=max(1,(int)floor($platformMaxBytes/1048576));
$configuredPhpExecution=max(30,min(3600,(int)setting($db,'php_max_execution_time','300')));
$configuredPhpInputTime=max(30,min(3600,(int)setting($db,'php_max_input_time','300')));

function bytes_from_ini(string $value): int {
    $value=trim($value); if($value==='') return 0;
    $last=strtolower($value[strlen($value)-1]); $n=(float)$value;
    if($last==='g') $n*=1024*1024*1024; elseif($last==='m') $n*=1024*1024; elseif($last==='k') $n*=1024;
    return (int)$n;
}
$uploadMax=bytes_from_ini((string)ini_get('upload_max_filesize'));
$postMax=bytes_from_ini((string)ini_get('post_max_size'));
$currentExecTime=(int)ini_get('max_execution_time');
$currentInputTime=(int)ini_get('max_input_time');
$currentMemoryLimit=(string)ini_get('memory_limit');
$currentMaxFileUploads=(int)ini_get('max_file_uploads');
$currentMaxInputVars=(int)ini_get('max_input_vars');
$currentFileUploads=(string)ini_get('file_uploads');
$loadedPhpIni=(string)(php_ini_loaded_file() ?: '');
$scannedPhpIni=(string)(php_ini_scanned_files() ?: '');
$userIniName=(string)ini_get('user_ini.filename');
$userIniPath=dirname(__DIR__).DIRECTORY_SEPARATOR.'.user.ini';
$userIniExists=is_file($userIniPath);
$userIniReadable=$userIniExists && is_readable($userIniPath);
$health['php_upload_limits'] = ($uploadMax===0 || $uploadMax >= $platformMaxBytes) && ($postMax===0 || $postMax >= $platformMaxBytes);
$health['php_runtime'] = ($currentExecTime===0 || $currentExecTime >= $configuredPhpExecution)
    && ($currentInputTime===-1 || $currentInputTime===0 || $currentInputTime >= $configuredPhpInputTime);
$phpRuntimeLimitedByHost=($currentExecTime>0 && $currentExecTime<$configuredPhpExecution)
    || ($currentInputTime>0 && $currentInputTime<$configuredPhpInputTime);
$sqliteVersion='No disponible';
try{
    $sqliteVersion=(string)$db->query('SELECT sqlite_version()')->fetchColumn();
}catch(Throwable $e){}
$mainDbSize=@filesize($config['database_path']);
$logsDbSize=@filesize($config['logs_database_path']);
$opcacheEnabled=function_exists('opcache_get_status') && (bool)@opcache_get_status(false);
$health['sqlite_version']=($sqliteVersion!=='No disponible');
$health['database_sizes']=($mainDbSize!==false && $logsDbSize!==false);
$health['opcache']=true;

$health['https'] = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0)===443));
$free = @disk_free_space($config['storage_path']);
$diskMinimumBytes = max($platformMaxBytes, 1);
$health['disk'] = $free !== false && $free >= $diskMinimumBytes;
$cronSnapshot=cron_heartbeat_snapshot($config);
$healthLastCleanup=(string)($cronSnapshot['last_run_at']??setting($db,'cron_last_run_at',setting($db,'last_cleanup_at','')));
$cronLastStatus=strtolower((string)($cronSnapshot['status']??setting($db,'cron_last_status','')));
$cronLastEpoch=(int)($cronSnapshot['last_run_epoch']??setting($db,'cron_last_run_epoch','0'));
$cronLastDurationMs=(int)($cronSnapshot['duration_ms']??setting($db,'cron_last_duration_ms','0'));
$cronLastDeleted=(int)($cronSnapshot['deleted']??setting($db,'cron_last_deleted','0'));
$cronLastError=trim((string)($cronSnapshot['error']??setting($db,'cron_last_error','')));
$cronLastSource=(string)($cronSnapshot['source']??setting($db,'cron_last_source','scheduled'));
$cronHasRun=$cronLastEpoch>0 && $healthLastCleanup!=='';
$cronStale=$cronHasRun && (time()-$cronLastEpoch)>=(26*3600);
$health['cron']=(!$cronHasRun) ? null : ($cronLastStatus==='success' && !$cronStale);
$healthAll = !in_array(false,$health,true);

$apiKeys=$db->query('SELECT * FROM api_keys ORDER BY created_at DESC')->fetchAll();
$newApiKey=$_SESSION['new_api_key']??null; unset($_SESSION['new_api_key']);
$footerTemplate=setting($db,'footer_template','{app_name}');
if (!validate_footer_template($footerTemplate)['ok']) { $footerTemplate='{app_name}'; }
// Logs: only available to authenticated admins.
$logLevel = strtoupper(trim((string)($_GET['log_level'] ?? 'ALL')));
$logEvent = trim((string)($_GET['log_event'] ?? ''));
$logQ = trim((string)($_GET['log_q'] ?? ''));
$logSecurity = (string)($_GET['log_security'] ?? 'all');
$logWhere=[]; $logParams=[];
if (in_array($logLevel,['DEBUG','INFO','NOTICE','WARNING','ERROR','CRITICAL'],true)) { $logWhere[]='severity=?'; $logParams[]=$logLevel; }
if ($logEvent!=='') { $logWhere[]='event_type=?'; $logParams[]=mb_substr($logEvent,0,100); }
if ($logSecurity==='security') $logWhere[]='security_event=1'; elseif($logSecurity==='all'){}
if ($logQ!=='') { $like='%'.mb_substr($logQ,0,120).'%'; $logWhere[]='(message LIKE ? OR actor_id LIKE ? OR target_id LIKE ? OR request_id LIKE ?)'; array_push($logParams,$like,$like,$like,$like); }
$skipAuditViewOnce=!empty($_SESSION['audit_skip_view_once']);
unset($_SESSION['audit_skip_view_once']);
$isRegisterView=(($_GET['admin_tab'] ?? $activeTab)==='register' || $logEvent!=='' || $logQ!=='' || $logLevel!=='ALL' || $logSecurity==='security');
if($isRegisterView && !$skipAuditViewOnce){
    audit_event($db,$config,'audit_log_view','INFO',true,'success','admin',null,'Administrador visualizó el registro.', ['filters'=>'applied']);
}
$logSql='SELECT * FROM audit_logs'.($logWhere?' WHERE '.implode(' AND ',$logWhere):'').' ORDER BY id DESC LIMIT 300';
$logStmt=$logDb->prepare($logSql);$logStmt->execute($logParams);$auditLogs=$logStmt->fetchAll();
$logCount=(int)$logDb->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
$securityCount=(int)$logDb->query('SELECT COUNT(*) FROM audit_logs WHERE security_event=1')->fetchColumn();
$failureCount=(int)$logDb->query("SELECT COUNT(*) FROM audit_logs WHERE outcome IN ('failure','blocked','error')")->fetchColumn();
$logIntegrity=null;
if($_SERVER['REQUEST_METHOD']==='POST' && $action==='verify_logs') { verify_admin_csrf(); $logIntegrity=audit_verify_chain($db,$config); audit_event($db,$config,'audit_log_verify','NOTICE',true,$logIntegrity['ok']?'success':'failure','admin',null,'Verificación de integridad de logs ejecutada.', ['checked'=>$logIntegrity['count'],'ok'=>$logIntegrity['ok']]); }

admin_layout_start('Administración',$branding,$config);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
  <div>
    <h1 class="h3 mb-1">Administración</h1>
    <div class="text-body-secondary">Configuración y archivos</div>
  </div>
  <form method="post" action="<?=e(app_base_url($config))?>/admin" class="admin-logout-form">
    <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
    <input type="hidden" name="action" value="logout">
    <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Salir</button>
  </form>
</div>

<?php if($notice): ?><div class="alert alert-success" role="alert"><?=e($notice)?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?=e($error)?></div><?php endif; ?>

<div class="admin-tabs" data-initial-tab="<?=e($activeTab)?>">
  <ul class="nav nav-tabs mb-4" id="adminTabs" role="tablist">
    <li class="nav-item" role="presentation"><button class="nav-link <?= $activeTab==='stats' ? 'active' : '' ?>" data-admin-tab="stats" type="button" role="tab"><i class="bi bi-bar-chart-line me-1"></i>Estadísticas</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= $activeTab==='customization' ? 'active' : '' ?>" data-admin-tab="customization" type="button" role="tab"><i class="bi bi-palette me-1"></i>Personalización</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= $activeTab==='links' ? 'active' : '' ?>" data-admin-tab="links" type="button" role="tab"><i class="bi bi-clock-history me-1"></i>Enlaces</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= $activeTab==='apis' ? 'active' : '' ?>" data-admin-tab="apis" type="button" role="tab"><i class="bi bi-key me-1"></i>APIs</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= $activeTab==='files' ? 'active' : '' ?>" data-admin-tab="files" type="button" role="tab"><i class="bi bi-folder2-open me-1"></i>Archivos</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= $activeTab==='security' ? 'active' : '' ?>" data-admin-tab="security" type="button" role="tab"><i class="bi bi-shield-lock me-1"></i>Seguridad</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= $activeTab==='health' ? 'active' : '' ?>" data-admin-tab="health" type="button" role="tab"><i class="bi bi-heart-pulse me-1"></i>Salud</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= $activeTab==='register' ? 'active' : '' ?>" data-admin-tab="register" type="button" role="tab"><i class="bi bi-journal-text me-1"></i>Registro</button></li>
  </ul>

  <style>
.admin-two-column-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:1.25rem;
  align-items:start;
}
.admin-two-column-grid > .col-12,
.admin-two-column-grid > [class*="col-"]{
  width:auto !important;
  max-width:none !important;
  margin:0 !important;
}
.admin-two-column-grid > .card{
  margin:0 !important;
  min-width:0;
}
@media (max-width: 991.98px){
  .admin-two-column-grid{grid-template-columns:1fr;}
}

      .health-four-column-grid{
        display:grid;
        grid-template-columns:repeat(4,minmax(0,1fr));
        gap:1.25rem;
        align-items:start;
      }
      .health-four-column-grid > [class*="col-"]{
        width:auto !important;
        max-width:none !important;
        min-width:0;
        margin:0 !important;
      }
      .health-four-column-grid > [class*="col-"] > .card{
        width:100%;
        margin:0 !important;
      }
      @media (max-width: 1199.98px){
        .health-four-column-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
      }
      @media (max-width: 575.98px){
        .health-four-column-grid{grid-template-columns:1fr;}
      }

      .stats-four-column-grid{
        display:grid;
        grid-template-columns:repeat(4,minmax(0,1fr));
        gap:1.25rem;
        align-items:start;
      }
      .stats-four-column-grid > [class*="col-"]{
        width:auto !important;
        max-width:none !important;
        min-width:0;
        margin:0 !important;
      }
      .stats-four-column-grid > [class*="col-"] > .stat{
        width:100%;
      }
      @media (max-width: 1199.98px){
        .stats-four-column-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
      }
      @media (max-width: 575.98px){
        .stats-four-column-grid{grid-template-columns:1fr;}
      }
</style>
<style>
      /* Shared admin UI rules: keep pills/buttons visually consistent across tabs. */
      .file-property-pill,
      .file-property-action{
        display:inline-flex !important;
        align-items:center;
        justify-content:center;
        gap:.25rem;
        min-height:22px;
        padding:.28rem .55rem !important;
        border-radius:999px !important;
        font-size:.75rem;
        line-height:1.05;
        white-space:nowrap;
        vertical-align:middle;
        text-decoration:none;
        box-sizing:border-box;
      }
      .file-property-action{
        cursor:pointer;
      }
      .admin-security-action{
        width:180px;
        min-width:180px;
        height:38px;
        display:inline-flex !important;
        align-items:center;
        justify-content:center;
        gap:.25rem;
      }
      @media (max-width: 575.98px){
        .admin-security-action{
          width:100%;
          min-width:0;
        }
      }
    </style>
<div class="tab-content" id="adminTabsContent">
    <div class="tab-pane <?= $activeTab==='customization' ? 'is-active' : '' ?>" id="tab-customization" role="tabpanel" tabindex="0">
      <div class="admin-two-column-grid customization-grid">

        <!-- 1. Marca -->
        <div class="col-12 col-xl-6">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
              <h2 class="h5">Marca del portal</h2>
              <p class="small text-body-secondary">Personaliza el logo y el texto de la cabecera.</p>
              <form method="post">
                <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
                <input type="hidden" name="action" value="save_branding">
                <input type="hidden" name="admin_tab" value="customization">
                <label class="form-label">Nombre / texto superior</label>
                <input class="form-control mb-3" name="app_name" value="<?=e($branding['name'])?>" maxlength="80" required>
                <label class="form-label">Texto secundario</label>
                <input class="form-control mb-3" name="app_tagline" value="<?=e($branding['tagline'])?>" maxlength="180">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Guardar cambios</button>
              </form>
              <hr>
              <div class="mb-2 fw-semibold">Logo actual</div>
              <?php if($branding['logo']): ?>
                <img src="<?=e(app_url($config,$branding['logo']))?>" class="admin-logo mb-3" alt="Logo">
                <form method="post" class="mb-3">
                  <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
                  <input type="hidden" name="action" value="remove_logo">
                  <input type="hidden" name="admin_tab" value="customization">
                  <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1" aria-hidden="true"></i>Eliminar logo</button>
                </form>
              <?php else: ?>
                <div class="text-body-secondary small mb-3">No hay logo personalizado.</div>
              <?php endif; ?>
              <form class="mt-3" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
                <input type="hidden" name="action" value="upload_logo">
                <input type="hidden" name="admin_tab" value="customization">
                <input class="form-control mb-2" type="file" name="logo" accept="image/png,image/jpeg,image/webp" required>
                <div class="small text-body-secondary mb-2">PNG, JPG o WEBP · máximo 2 MB.</div>
                <button type="submit" class="btn btn-outline-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>Subir logo</button>
              </form>
            </div>
          </div>
        </div>

        <!-- 2. Plantillas para compartir -->
        <div class="col-12 col-xl-6">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
              <h2 class="h5">Plantilla para compartir</h2>
              <p class="small text-body-secondary">Define una plantilla para Español y otra para English. Se usará según el idioma del usuario; si no está disponible, se usa el idioma predeterminado.</p>
              <form method="post">
                <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
                <input type="hidden" name="action" value="save_share_templates">
                <input type="hidden" name="admin_tab" value="customization">
                <div class="row g-3">
                  <div class="col-12 col-xl-6">
                    <label class="form-label"><i class="bi bi-translate me-1"></i>Español</label>
                    <textarea class="form-control share-template-editor" name="share_template_es" rows="14" maxlength="5000" required><?=e($shareTemplateEs)?></textarea>
                  </div>
                  <div class="col-12 col-xl-6">
                    <label class="form-label"><i class="bi bi-translate me-1"></i>English</label>
                    <textarea class="form-control share-template-editor" name="share_template_en" rows="14" maxlength="5000" required><?=e($shareTemplateEn)?></textarea>
                  </div>
                </div>
                <div class="small text-body-secondary mt-2 mb-3">
                  Tags obligatorios en ambos idiomas:
                  <code>{app_name}</code> <code>{filename}</code> <code>{url}</code> <code>{pin}</code>
                  <code>{sha256}</code> <code>{expires_at}</code> <code>{expires_at_iso}</code>
                  <code>{duration}</code> <code>{duration_hours}</code>.
                  No se permiten tags desconocidos.
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-files me-1"></i>Guardar plantillas</button>
              </form>
            </div>
          </div>
        </div>

        <!-- 4. Footer -->
        <div class="col-12 col-xl-6">
          <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
              <h2 class="h5">Pie de página público</h2>
              <p class="small text-body-secondary">Controla el texto del pie público. En administración se utiliza esta misma plantilla y la plataforma agrega automáticamente la versión al final.</p>
              <form method="post">
                <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
                <input type="hidden" name="action" value="save_footer_template">
                <input type="hidden" name="admin_tab" value="customization">
                <textarea class="form-control mb-2" name="footer_template" rows="5" maxlength="1000" required><?=e($footerTemplate)?></textarea>
                <div class="small text-body-secondary mb-3">Único tag permitido: <code>{app_name}</code>. La versión se agrega automáticamente únicamente en el pie administrativo.</div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-layout-text-window-reverse me-1" aria-hidden="true"></i>Guardar pie de página</button>
              </form>
            </div>
          </div>
        </div>

      </div>
    </div>

    <div class="tab-pane <?= $activeTab==='links' ? 'is-active' : '' ?>" id="tab-links" role="tabpanel" tabindex="0">
      
            <div class="admin-two-column-grid links-settings-grid">
      <div class="card border-0 shadow-sm"><div class="card-body">
        <h2 class="h5">Duración de los enlaces</h2>
        <p class="text-body-secondary">La duración se configura directamente en horas. Al guardar, se aplica retroactivamente a los enlaces que sigan vigentes calculando la nueva expiración desde su fecha de creación. Los ya expirados no se reactivan.</p>
        <form method="post" data-confirm="¿Aplicar esta duración a todos los enlaces que todavía estén vigentes? Los enlaces expirados no se reactivarán.">
          <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
          <input type="hidden" name="action" value="save_duration">
          <input type="hidden" name="admin_tab" value="links">
          <div class="input-group mb-2" style="max-width:520px"><input type="number" class="form-control" name="duration_hours" min="1" max="8760" step="1" value="<?=e((string)$durationHours)?>" required><span class="input-group-text">horas</span></div>
          <div class="small text-body-secondary mb-3">24 horas = 1 día · 72 horas = 3 días · 168 horas = 7 días · máximo 8760 horas.</div>
          <button type="submit" class="btn btn-primary"><i class="bi bi-clock-history me-1"></i>Guardar y aplicar</button>
        </form>
      </div></div>

<div class="card border-0 shadow-sm mt-4">
  <div class="card-body">
    <h2 class="h5"><i class="bi bi-globe2 me-1"></i>Zona horaria del sistema</h2>
    <p class="text-body-secondary">
      Selecciona una zona horaria soportada por PHP. El sistema la utilizará para fechas, expiraciones, registros y demás operaciones que dependan de la hora.
      Valor inicial: <strong>la zona horaria configurada por PHP en el servidor</strong>.
    </p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
      <input type="hidden" name="action" value="save_timezone">
      <input type="hidden" name="admin_tab" value="links">
      <select class="form-select mb-2" name="timezone" style="max-width:520px" required>
        <?php foreach($phpTimezones as $tz): ?>
          <option value="<?=e($tz)?>" <?=$selectedTimezone===$tz?'selected':''?>><?=e($tz)?></option>
        <?php endforeach; ?>
      </select>
      <div class="small text-body-secondary mb-3">
        La lista proviene de <code>DateTimeZone::listIdentifiers()</code>. Al guardar también se actualiza <code>public/php.ini</code> con <code>date.timezone</code>.
      </div>
      <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar zona horaria</button>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm mt-4">
  <div class="card-body">
    <h2 class="h5"><i class="bi bi-stopwatch me-1"></i>Tiempo de ejecución de PHP</h2>
    <p class="text-body-secondary">
      Ajusta cuánto tiempo puede ejecutarse PHP y cuánto tiempo puede procesar la entrada de una petición.
      Es especialmente útil con archivos grandes. El hosting puede imponer un límite inferior.
    </p>
    <form method="post" class="row g-3 align-items-end">
      <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
      <input type="hidden" name="action" value="save_php_runtime_limits">
      <input type="hidden" name="admin_tab" value="links">
      <div class="col-12 col-md-4">
        <label class="form-label" for="php_max_execution_time">Tiempo máximo de ejecución</label>
        <div class="input-group"><input id="php_max_execution_time" class="form-control" type="number" min="30" max="3600" step="1" name="php_max_execution_time" value="<?=e((string)$configuredPhpExecution)?>" required><span class="input-group-text">seg.</span></div>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label" for="php_max_input_time">Tiempo máximo de entrada</label>
        <div class="input-group"><input id="php_max_input_time" class="form-control" type="number" min="30" max="3600" step="1" name="php_max_input_time" value="<?=e((string)$configuredPhpInputTime)?>" required><span class="input-group-text">seg.</span></div>
      </div>
      <div class="col-12 col-md-4">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar límites PHP</button>
      </div>
    </form>
    <div class="small text-body-secondary mt-3">
      Se escriben en <code>public/php.ini</code> y <code>public/.user.ini</code>. Valores permitidos: 30–3600 segundos. Si el proveedor no permite cambiar estos parámetros, Salud mostrará el valor efectivo que PHP está usando.
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm mt-4">
  <div class="card-body">
    <h2 class="h5"><i class="bi bi-file-earmark-arrow-up me-1"></i>Límite máximo de carga por archivo</h2>
    <p class="text-body-secondary">
      Define el tamaño máximo permitido para cada archivo. El valor se expresa en MB y también actualiza <code>upload_max_filesize</code> y <code>post_max_size</code> en <code>public/php.ini</code> y <code>public/.user.ini</code>.
    </p>
    <form method="post" class="row g-3 align-items-end">
      <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
      <input type="hidden" name="action" value="save_max_file_size">
      <input type="hidden" name="admin_tab" value="links">
      <div class="col-12 col-md-5">
        <label class="form-label fw-semibold" for="max_file_size_mb">Máximo por archivo</label>
        <div class="input-group">
          <input id="max_file_size_mb" type="number" class="form-control" name="max_file_size_mb" min="1" max="1048576" step="1" value="<?=e((string)platform_max_file_size_mb($db,$config))?>" required>
          <span class="input-group-text">MB</span>
        </div>
      </div>
      <div class="col-12 col-md-7">
        <div class="small text-body-secondary">
          <?=e('Equivale a '.format_bytes(platform_max_file_size_bytes($db,$config)).'. El valor máximo permitido es 1,048,576 MB.')?>
        </div>
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar límite de carga</button>
      </div>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm mt-4">
  <div class="card-body">
    <h2 class="h5"><i class="bi bi-speedometer2 me-1"></i>Límite de cargas</h2>
    <p class="text-body-secondary">
      Controla cuántos archivos puede iniciar una misma dirección IP dentro de una ventana de una hora.
      Este límite aplica tanto a la carga web como a las APIs.
    </p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
      <input type="hidden" name="action" value="save_upload_rate_limit">
      <input type="hidden" name="admin_tab" value="links">
      <div class="input-group mb-2" style="max-width:520px">
        <input type="number" class="form-control" name="upload_rate_limit_per_hour" min="0" max="1000000" step="1" value="<?=e((string)get_setting_int($db,'upload_rate_limit_per_hour',(int)($config['upload_rate_limit_per_hour'] ?? 20)))?>" required>
        <span class="input-group-text">cargas / IP / hora</span>
      </div>
      <div class="small text-body-secondary mb-3">
        0 = ilimitado. El límite se aplica al iniciar una carga; una carga que ya comenzó puede completar sus chunks.
        La aplicación usa la IP de origen observada por PHP (REMOTE_ADDR).
      </div>
      <button type="submit" class="btn btn-primary"><i class="bi bi-shield-check me-1"></i>Guardar límite</button>
    </form>
  </div>
</div>
      </div>
    </div>

        <div class="tab-pane <?= $activeTab==='apis' ? 'is-active' : '' ?>" id="tab-apis" role="tabpanel" tabindex="0">
  <div class="card border-0 shadow-sm"><div class="card-body">
    <h2 class="h5"><i class="bi bi-key me-1"></i>APIs</h2>
    <p class="small text-body-secondary"><?=e($uiLanguage==='en'?'Unlimited secrets. Each secret has independent permissions, hourly limits and daily quotas.':'Secrets ilimitados. Cada secret tiene permisos, límites por hora y cuotas diarias independientes.')?></p>

    <form method="post" class="row g-3 mb-3">
      <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
      <input type="hidden" name="action" value="create_api_key">
      <input type="hidden" name="admin_tab" value="apis">

      <div class="col-lg-3">
        <label class="form-label"><?=e($uiLanguage==='en'?'Name':'Nombre')?></label>
        <input class="form-control" name="key_name" maxlength="80" placeholder="<?=e($uiLanguage==='en'?'Example: ERP billing':'Ej. ERP facturación')?>" required>
      </div>
      <div class="col-lg-2">
        <label class="form-label"><?=e($uiLanguage==='en'?'Uploads/hour':'Cargas/h')?></label>
        <input type="number" class="form-control" name="requests_per_hour" min="0" max="1000000" value="0" required>
      </div>
      <div class="col-lg-2">
        <label class="form-label"><?=e($uiLanguage==='en'?'Files/day':'Archivos/día')?></label>
        <input type="number" class="form-control" name="quota_files_per_day" min="0" max="1000000" value="0" required>
      </div>
      <div class="col-lg-2">
        <label class="form-label"><?=e($uiLanguage==='en'?'GB/day':'GB/día')?></label>
        <input type="number" class="form-control" name="quota_gb_per_day" min="0" max="1000000" value="0" required>
      </div>
      <div class="col-lg-3">
        <label class="form-label"><?=e($uiLanguage==='en'?'Permissions':'Permisos')?></label>
        <div class="d-flex flex-column gap-2">
          <?php foreach(api_scopes_all() as $scope): $scopeId='new_'.str_replace('.','_',$scope); ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="scopes[]" value="<?=e($scope)?>" id="<?=e($scopeId)?>" <?=($scope==='files.upload')?'checked':''?>>
              <label class="form-check-label" for="<?=e($scopeId)?>">
                <?=e(api_scope_label($scope,$uiLanguage))?>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="col-12">
        <button type="submit" class="btn btn-primary"><i class="bi bi-key-fill me-1"></i><?=e($uiLanguage==='en'?'Create secret':'Crear secret')?></button>
      </div>
    </form>

    <div class="small text-body-secondary mb-3"><?=e($uiLanguage==='en'?'0 = unlimited. The full secret is displayed only once.':'0 = ilimitado. El secret completo se muestra una sola vez.')?></div>

    <?php if($newApiKey): ?>
      <div class="alert alert-warning">
        <div class="fw-semibold mb-1"><?=e($uiLanguage==='en'?'New secret — copy it now':'Nuevo secret — cópialo ahora')?></div>
        <div class="input-group">
          <input id="newApiKey" class="form-control font-monospace" value="<?=e($newApiKey)?>" readonly>
          <button type="button" class="btn btn-outline-secondary" data-copy-value="<?=e($newApiKey)?>">
            <i class="bi bi-copy me-1"></i><?=e($uiLanguage==='en'?'Copy':'Copiar')?>
          </button>
        </div>
      </div>
    <?php endif; ?>

    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr>
          <th><?=e($uiLanguage==='en'?'Service':'Servicio')?></th>
          <th><?=e($uiLanguage==='en'?'Permissions':'Permisos')?></th>
          <th><?=e($uiLanguage==='en'?'Limit':'Límite')?></th>
          <th><?=e($uiLanguage==='en'?'Quotas/day':'Cuotas/día')?></th>
          <th><?=e($uiLanguage==='en'?'Usage':'Uso')?></th>
          <th><?=e($uiLanguage==='en'?'Actions':'Acciones')?></th>
        </tr></thead>
        <tbody>
        <?php foreach($apiKeys as $k): $scopes=api_scopes_decode($k['scopes_json']??'[]'); ?>
          <tr>
            <td>
              <div class="fw-semibold"><?=e($k['name'])?></div>
              <div class="small font-monospace text-body-secondary"><?=e($k['key_prefix'])?>…</div>
              <div class="small">
                <?= $k['revoked_at']
                  ? '<span class="badge text-bg-secondary">'.e($uiLanguage==='en'?'Revoked':'Revocada').'</span>'
                  : '<span class="badge text-bg-success">'.e($uiLanguage==='en'?'Active':'Activa').'</span>' ?>
              </div>
            </td>
            <td class="small">
              <?php foreach($scopes as $scope): ?>
                <div><?=e(api_scope_label($scope,$uiLanguage))?></div>
              <?php endforeach; ?>
            </td>
            <td><?=((int)$k['requests_per_hour']===0)?e($uiLanguage==='en'?'Unlimited':'Ilimitado'):number_format((int)$k['requests_per_hour']).'/h'?></td>
            <td class="small">
              <?=((int)$k['quota_files_per_day']===0)?'∞':number_format((int)$k['quota_files_per_day'])?> <?=e($uiLanguage==='en'?'files':'archivos')?><br>
              <?=((int)$k['quota_bytes_per_day']===0)?'∞':format_bytes((int)$k['quota_bytes_per_day'])?>
            </td>
            <td class="small">
              <?=number_format((int)$k['request_count'])?> <?=e($uiLanguage==='en'?'requests':'solicitudes')?><br>
              <?=e($k['last_used_at']?format_date($k['last_used_at']):($uiLanguage==='en'?'Never':'Sin uso'))?>
            </td>
            <td>
              <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#apiPolicy<?=e((string)$k['id'])?>" title="<?=e($uiLanguage==='en'?'Edit policy':'Editar política')?>"><i class="bi bi-sliders"></i></button>
              <?php if(!$k['revoked_at']): ?>
                <form class="d-inline" method="post" data-confirm="<?=e($uiLanguage==='en'?'Revoke this secret? The service will no longer be able to use the API.':'¿Revocar este secret? El servicio dejará de poder utilizar la API.')?>">
                  <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
                  <input type="hidden" name="action" value="revoke_api_key">
                  <input type="hidden" name="api_key_id" value="<?=e((string)$k['id'])?>">
                  <input type="hidden" name="admin_tab" value="apis">
                  <button type="submit" class="btn btn-sm btn-outline-warning" title="<?=e($uiLanguage==='en'?'Revoke':'Revocar')?>"><i class="bi bi-slash-circle"></i></button>
                </form>
              <?php endif; ?>
              <form class="d-inline" method="post" data-confirm="<?=e($uiLanguage==='en'?'Delete this secret permanently? It cannot be recovered.':'¿Eliminar permanentemente este secret? No podrá recuperarse.')?>">
                <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
                <input type="hidden" name="action" value="delete_api_key">
                <input type="hidden" name="api_key_id" value="<?=e((string)$k['id'])?>">
                <input type="hidden" name="admin_tab" value="apis">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="<?=e($uiLanguage==='en'?'Delete':'Eliminar')?>"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>

          <div class="modal fade" id="apiPolicy<?=e((string)$k['id'])?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg"><div class="modal-content">
              <form method="post">
                <div class="modal-header">
                  <h5 class="modal-title"><i class="bi bi-sliders me-1"></i><?=e($uiLanguage==='en'?'Policy':'Política')?>: <?=e($k['name'])?></h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?=e($uiLanguage==='en'?'Close':'Cerrar')?>"></button>
                </div>
                <div class="modal-body">
                  <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
                  <input type="hidden" name="action" value="update_api_policy">
                  <input type="hidden" name="api_key_id" value="<?=e((string)$k['id'])?>">
                  <input type="hidden" name="admin_tab" value="apis">
                  <div class="row g-3">
                    <div class="col-md-4"><label class="form-label"><?=e($uiLanguage==='en'?'Uploads/hour':'Cargas/h')?></label><input type="number" class="form-control" name="requests_per_hour" min="0" max="1000000" value="<?=e((string)$k['requests_per_hour'])?>"></div>
                    <div class="col-md-4"><label class="form-label"><?=e($uiLanguage==='en'?'Files/day':'Archivos/día')?></label><input type="number" class="form-control" name="quota_files_per_day" min="0" max="1000000" value="<?=e((string)$k['quota_files_per_day'])?>"></div>
                    <div class="col-md-4"><label class="form-label"><?=e($uiLanguage==='en'?'GB/day':'GB/día')?></label><input type="number" class="form-control" name="quota_gb_per_day" min="0" max="1000000" value="<?=e((string)intdiv((int)$k['quota_bytes_per_day'],1024*1024*1024))?>"></div>
                    <div class="col-12">
                      <label class="form-label"><?=e($uiLanguage==='en'?'Permissions':'Permisos')?></label>
                      <div class="d-flex flex-column gap-2">
                        <?php foreach(api_scopes_all() as $scope): $sid='k'.$k['id'].'_'.str_replace('.','_',$scope); ?>
                          <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="scopes[]" value="<?=e($scope)?>" id="<?=e($sid)?>" <?=in_array($scope,$scopes,true)?'checked':''?>>
                            <label class="form-check-label" for="<?=e($sid)?>"><?=e(api_scope_label($scope,$uiLanguage))?></label>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i><?=e($uiLanguage==='en'?'Cancel':'Cancelar')?></button>
                  <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i><?=e($uiLanguage==='en'?'Save changes':'Guardar cambios')?></button>
                </div>
              </form>
            </div></div>
          </div>
        <?php endforeach; ?>
        <?php if(!$apiKeys): ?>
          <tr><td colspan="6" class="text-body-secondary"><?=e($uiLanguage==='en'?'No secrets have been created yet.':'No hay secrets creados.')?></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="small text-body-secondary mt-2">
      <?=e($uiLanguage==='en'
        ? 'Technical scopes remain: files.upload, files.read and files.delete.'
        : 'Permisos técnicos internos: files.upload, files.read y files.delete.')?>
    </div>
  </div></div>
</div>
<div class="tab-pane <?= $activeTab==='files' ? 'is-active' : '' ?>" id="tab-files" role="tabpanel" tabindex="0">
      <div class="card border-0 shadow-sm"><div class="card-body">
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
          <div><h2 class="h5 mb-1">Archivos y enlaces</h2><div class="small text-body-secondary">Peso, enlace, expiración y descargas. El PIN nunca se muestra.</div></div>
          <div class="d-flex gap-2">
            <a href="<?=e(app_base_url($config))?>/" class="btn btn-sm btn-primary">Abrir portal</a>
            <?php if($expired): ?><form method="post" data-confirm="¿Eliminar todos los archivos expirados? Esta acción no se puede deshacer."><input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>"><input type="hidden" name="action" value="delete_expired"><input type="hidden" name="admin_tab" value="files"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3 me-1"></i>Limpiar expirados</button></form><?php endif; ?>
          </div>
        </div>
        <form class="row g-2 mb-3" method="get" id="fileFilterForm">
          <div class="col-md-6"><input class="form-control" name="q" value="<?=e($q)?>" placeholder="Buscar por nombre o identificador del enlace"></div>
          <div class="col-md-3"><select class="form-select" name="status"><option value="active" <?=$status==='active'?'selected':''?>>Activos</option><option value="expired" <?=$status==='expired'?'selected':''?>>Expirados</option><option value="all" <?=$status==='all'?'selected':''?>>Todos</option></select></div>
          <div class="col-md-3 d-flex gap-2"><button type="submit" class="btn btn-outline-primary flex-grow-1"><i class="bi bi-search me-1"></i>Buscar</button><a class="btn btn-outline-secondary" href="<?=e(app_base_url($config))?>/admin">Limpiar filtros</a></div>
        </form>
        <div class="table-responsive"><table class="table align-middle table-hover"><thead><tr><th>Archivo</th><th>Peso</th><th>SHA-256</th><th>Propiedades</th><th>Enlace</th><th>Estado</th><th>Expira</th><th>Descargas</th><th></th></tr></thead><tbody>
        <?php foreach($files as $f):
  $expiredRow=is_expired($f);
  $url=$base.'/f/'.$f['download_id'];
  $physical=is_file(safe_file_path($config,$f['stored_name']));
  $pinSubjectHash=auth_subject_hash((string)$f['download_id']);
  $pinSec=$pinSecurity[$pinSubjectHash]??['ip_count'=>0,'total_attempts'=>0,'max_attempts'=>0,'blocked'=>false];
?>
          <tr><td><div class="fw-semibold text-break"><?=e($f['original_name'])?></div><div class="small text-body-secondary">ID: <?=e($f['download_id'])?></div></td>
          <td><?=e(format_bytes((int)$f['file_size']))?></td><td style="min-width:260px"><div class="input-group input-group-sm"><input class="form-control font-monospace" id="sha-<?=e((string)$f['id'])?>" value="<?=e($f['sha256'])?>" readonly><button type="button" class="btn btn-outline-secondary" data-copy-target="sha-<?=e((string)$f['id'])?>" title="Copiar SHA-256"><i class="bi bi-copy"></i></button></div></td>
          <td style="min-width:220px">
            <?php
              $createdTs=strtotime((string)$f['created_at']);
              $expiresTs=strtotime((string)$f['expires_at']);
              $effectiveHours=($createdTs!==false && $expiresTs!==false && $expiresTs>$createdTs)?round(($expiresTs-$createdTs)/3600,1):0;
            ?>
            <div class="file-properties d-flex flex-wrap gap-1">
              <span class="badge rounded-pill file-property-pill text-bg-secondary" title="Duración efectiva"><i class="bi bi-clock me-1"></i><?=e(rtrim(rtrim(number_format($effectiveHours,1,'.',''),'0'),'.'))?> h</span>
              <span class="badge rounded-pill file-property-pill text-bg-secondary" title="Máximo de descargas"><i class="bi bi-download me-1"></i><?=((int)$f['max_downloads']===0)?'Ilimitadas':number_format((int)$f['max_downloads'])?></span>
              <span class="badge rounded-pill file-property-pill <?=((int)$f['one_time']===1)?'text-bg-warning':'text-bg-secondary'?>" title="<?=((int)$f['one_time']===1)?'Enlace de un solo uso':(((int)$f['max_downloads']>0)?'Enlace multiuso: permite hasta '.number_format((int)$f['max_downloads']).' descargas':'Enlace multiuso: permite descargas ilimitadas')?>"><i class="bi bi-lightning-charge me-1"></i><?=((int)$f['one_time']===1)?'Un solo uso':'Multiuso'?></span>
              <?php if((int)($f['antivirus_scanned'] ?? 0)===1): ?>
                <span class="badge rounded-pill file-property-pill text-bg-success" title="Archivo analizado y limpio por el antivirus">
                  <i class="bi bi-shield-check me-1"></i>Analizado
                </span>
              <?php elseif(antivirus_enabled($db) && $physical): ?>
                <form method="post" class="d-inline" data-confirm="¿Analizar este archivo con el antivirus ahora? Si se detecta una amenaza, el archivo y su enlace serán eliminados inmediatamente.">
                  <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
                  <input type="hidden" name="action" value="scan_existing_file">
                  <input type="hidden" name="id" value="<?=e((string)$f['id'])?>">
                  <input type="hidden" name="admin_tab" value="files">
                  <button type="submit" class="badge rounded-pill file-property-action text-bg-success border-0 shadow-none" title="Analizar archivo con el antivirus">
                    <i class="bi bi-shield-check me-1"></i>Analizar
                  </button>
                </form>
              <?php endif; ?>
              <?php if($pinSec['blocked']): ?>
                <span class="badge rounded-pill file-property-pill text-bg-danger" title="PIN bloqueado por intentos incorrectos">
                  <i class="bi bi-lock-fill me-1"></i>PIN bloqueado · <?=number_format($pinSec['ip_count'])?> IP<?=($pinSec['ip_count']===1?'':'s')?>
                </span>
                <span class="badge rounded-pill file-property-pill text-bg-secondary" title="Intentos fallidos dentro de la ventana actual">
                  <?=number_format($pinSec['total_attempts'])?>/5 intentos
                </span>
                <form method="post" class="d-inline" data-confirm="¿Desbloquear el PIN de este enlace? Se eliminarán los bloqueos por intentos incorrectos de este enlace.">
                  <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
                  <input type="hidden" name="action" value="unlock_file_pin">
                  <input type="hidden" name="download_id" value="<?=e($f['download_id'])?>">
                  <input type="hidden" name="admin_tab" value="files">
                  <button type="submit" class="badge rounded-pill file-property-action text-bg-warning border-0 shadow-none" title="Desbloquear PIN">
                    <i class="bi bi-unlock me-1"></i>Desbloquear
                  </button>
                </form>
              <?php elseif($pinSec['total_attempts']>0): ?>
                <span class="badge rounded-pill file-property-pill text-bg-warning" title="Intentos fallidos dentro de la ventana actual">
                  <i class="bi bi-shield-exclamation me-1"></i><?=number_format($pinSec['total_attempts'])?>/5 intentos
                </span>
              <?php else: ?>
                <span class="badge rounded-pill file-property-pill text-bg-success" title="Sin intentos incorrectos en la ventana actual">
                  <i class="bi bi-shield-check me-1"></i>PIN sin bloqueos
                </span>
              <?php endif; ?>
            </div>
          </td>
          <td style="min-width:260px"><div class="input-group input-group-sm"><input class="form-control" id="url-<?=e((string)$f['id'])?>" value="<?=e($url)?>" readonly><button type="button" class="btn btn-outline-secondary copy-url" data-target="url-<?=e((string)$f['id'])?>" title="Copiar enlace"><i class="bi bi-copy"></i></button></div><a class="small" href="<?=e($url)?>" target="_blank" rel="noopener">Abrir enlace</a></td>
          <td><?php if(!$physical): ?><span class="badge text-bg-danger">Físico ausente</span><?php elseif($expiredRow): ?><span class="badge text-bg-secondary">Expirado</span><?php else: ?><span class="badge text-bg-success">Activo</span><?php endif; ?></td>
          <td><?=e(format_date($f['expires_at']))?></td><td><?=number_format((int)$f['downloads'])?></td>
          <td><form method="post" data-confirm="¿Eliminar este archivo y su enlace inmediatamente? Esta acción no se puede deshacer."><input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>"><input type="hidden" name="action" value="delete_file"><input type="hidden" name="id" value="<?=e((string)$f['id'])?>"><input type="hidden" name="admin_tab" value="files"><button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar inmediatamente"><i class="bi bi-trash"></i></button></form></td></tr>
        <?php endforeach; ?>
        <?php if(!$files): ?><tr><td colspan="9" class="text-center text-body-secondary py-5"><i class="bi bi-folder2-open fs-2 d-block mb-2"></i>No hay archivos que coincidan con el filtro.</td></tr><?php endif; ?>
        </tbody></table></div>
        <div class="small text-body-secondary mt-2">
  Mostrando hasta 500 registros. El almacenamiento físico se calcula sobre los archivos realmente presentes en <code>storage/files</code>.
  Los bloqueos de PIN se calculan sobre la ventana actual de 5 minutos; el PIN nunca se muestra.
</div>
      </div></div>
    </div>

    <div class="tab-pane <?= $activeTab==='security' ? 'is-active' : '' ?>" id="tab-security" role="tabpanel" tabindex="0">
      
      <div class="admin-two-column-grid security-settings-grid">
      <?php
        $antivirusEnabled=antivirus_enabled($db);
        $antivirusCommand=antivirus_command_template($db);
        $antivirusTestStatus='';
        $antivirusStatusLabel=$antivirusEnabled?'Activado':'Desactivado';
      ?>
<div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
          <?php
            $maintenanceEnabled = maintenance_enabled($db);
            $isEnglishAdmin = (($uiLanguage ?? $lang ?? 'en') === 'en');
          ?>
          <div class="d-flex align-items-start justify-content-between gap-4 flex-wrap">
            <div>
              <h2 class="h5 mb-1"><i class="bi bi-cone-striped me-1" aria-hidden="true"></i><?=$isEnglishAdmin?'Maintenance mode':'Modo mantenimiento'?></h2>
              <p class="text-body-secondary mb-0">
                <?=$isEnglishAdmin
                    ? 'Keep the public website and API unavailable while Administration remains accessible.'
                    : 'Mantiene la web pública y la API fuera de servicio mientras Administración sigue disponible.'?>
              </p>
            </div>
            <form method="post" action="<?=e(app_base_url($config))?>/admin" class="m-0 maintenance-mode-form" id="maintenance-mode-form">
              <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
              <input type="hidden" name="action" value="save_maintenance_mode">
              <input type="hidden" name="admin_tab" value="security">
              <input type="hidden" name="maintenance_mode" id="maintenance_mode_value" value="<?=$maintenanceEnabled?'1':'0'?>">
              <div class="form-check form-switch form-switch-lg d-flex align-items-center gap-3">
                <input
                  class="form-check-input"
                  type="checkbox"
                  role="switch"
                  id="maintenance_mode"
                  <?=$maintenanceEnabled?'checked':''?>
                  >
                <label class="form-check-label fw-semibold mb-0" for="maintenance_mode" id="maintenance-mode-label">
                  <?=$maintenanceEnabled
                    ? ($isEnglishAdmin?'Enabled':'Activado')
                    : ($isEnglishAdmin?'Disabled':'Desactivado')?>
                </label>
              </div>
            </form>
          </div>
          <div class="small text-body-secondary mt-3">
            <?=$isEnglishAdmin
              ? 'New installations start with maintenance mode enabled so the administrator must enter this panel first.'
              : 'Las instalaciones nuevas comienzan con el modo mantenimiento activado para obligar al administrador a entrar primero a este panel.'?>
          </div>
        </div>
      </div>

      <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
          <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
            <div>
              <h2 class="h5 mb-1"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Antivirus</h2>
              <p class="text-body-secondary mb-0">Analiza cada archivo antes de publicarlo. El archivo permanece temporal hasta que el antivirus devuelve un resultado limpio.</p>
            </div>
            <span class="badge <?= $antivirusEnabled?'text-bg-success':'text-bg-secondary' ?>"><?=e($antivirusStatusLabel)?></span>
          </div>

          <form method="post" class="mt-3">
            <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
            <input type="hidden" name="action" value="save_antivirus_settings">
            <input type="hidden" name="admin_tab" value="security">

            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" role="switch" id="antivirus_enabled" name="antivirus_enabled" value="1" <?=$antivirusEnabled?'checked':''?>>
              <label class="form-check-label fw-semibold" for="antivirus_enabled">Habilitar análisis antes de publicar</label>
            </div>

            <label class="form-label" for="antivirus_command">Comando de análisis</label>
            <input type="text" class="form-control font-monospace" id="antivirus_command" name="antivirus_command" value="<?=e($antivirusCommand)?>" required spellcheck="false" autocomplete="off">
            <div class="form-text">
              Usa <code>{file}</code> como marcador del archivo temporal. Ejemplo: <code>clamdscan --no-summary {file}</code>.
              Por seguridad no se permiten separadores, redirecciones, sustituciones ni comillas de shell.
            </div>

            <div class="d-flex flex-wrap gap-2 mt-3 align-items-center">
              <button type="submit" class="btn btn-sm btn-primary admin-security-action"><i class="bi bi-save2 me-1" aria-hidden="true"></i>Guardar configuración</button>
          </form>

          <form method="post" class="d-inline m-0">
            <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
            <input type="hidden" name="action" value="test_antivirus">
            <input type="hidden" name="admin_tab" value="security">
            <button type="submit" class="btn btn-sm btn-outline-primary admin-security-action"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Probar antivirus</button>
          </form>
            </div>

          <div class="alert <?= $antivirusEnabled ? 'alert-success' : 'alert-warning' ?> mt-3 mb-0" role="status">
            <?php if($antivirusEnabled): ?>
              <i class="bi bi-shield-check me-1" aria-hidden="true"></i>
              <strong>Antivirus activo.</strong> Los archivos se analizarán antes de publicarse.
            <?php else: ?>
              <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
              <strong>Antivirus no configurado.</strong> En instalaciones nuevas el análisis está desactivado hasta que configures el comando y la prueba sea satisfactoria. Mientras permanezca desactivado, la carga funciona como antes, sin análisis antivirus.
            <?php endif; ?>
          </div>

          <div class="small text-body-secondary mt-3">
            El proveedor actual utiliza <code>clamdscan --no-summary {file}</code>. Cada instalación puede introducir el comando que su proveedor exponga, siempre usando <code>{file}</code>.
          </div>
        </div>
      </div>

      <div class="card border-0 shadow-sm"><div class="card-body">
        <h2 class="h5">Seguridad</h2>
        <p class="text-body-secondary">El PIN de los archivos no se puede consultar ni regenerar desde el panel.</p>
        <form method="post">
          <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
          <input type="hidden" name="action" value="change_password">
          <input type="hidden" name="admin_tab" value="security">
          <label class="form-label">Contraseña actual</label><input type="password" name="current_password" class="form-control mb-3" required>
          <label class="form-label">Nueva contraseña</label><input type="password" name="new_password" minlength="10" class="form-control mb-3" required>
          <label class="form-label">Repite la nueva contraseña</label><input type="password" name="new_password2" minlength="10" class="form-control mb-3" required>
          <button type="submit" class="btn btn-outline-primary"><i class="bi bi-key me-1" aria-hidden="true"></i>Cambiar contraseña</button>
        </form>
      </div></div>
      </div>
    </div>

    <div class="tab-pane <?= $activeTab==='stats' ? 'is-active' : '' ?>" id="tab-stats" role="tabpanel" tabindex="0">
      <div class="mb-4">
        <h2 class="h5 mb-3"><i class="bi bi-speedometer2 me-1"></i>Resumen general</h2>
        <div class="stats-four-column-grid">
          <div class="col-sm-6 col-xl-3"><div class="stat h-100"><span>Total de archivos</span><strong><?=number_format($total)?></strong><small><?=e(format_bytes($bytes))?> registrados</small></div></div>
          <div class="col-sm-6 col-xl-3"><div class="stat h-100"><span>Archivos activos</span><strong><?=number_format($active)?></strong><small><?=e(format_bytes($activeBytes))?> en uso</small></div></div>
          <div class="col-sm-6 col-xl-3"><div class="stat h-100"><span>Descargas acumuladas</span><strong><?=number_format($downloads)?></strong><small>descargas registradas</small></div></div>
          <div class="col-sm-6 col-xl-3"><div class="stat h-100"><span>Espacio físico</span><strong><?=e(format_bytes($physicalBytes))?></strong><small><?=number_format($expired)?> expirados pendientes</small></div></div>
        </div>
      </div>
      <div class="admin-two-column-grid mb-3">
        <div class="col-md-4"><div class="stat h-100"><span>Subidas · 24 h</span><strong><?=number_format($statsTodayUploads)?></strong><small><?=e(format_bytes($statsTodayBytes))?></small></div></div>
        <div class="col-md-4"><div class="stat h-100"><span>Subidas · 7 días</span><strong><?=number_format($statsWeekUploads)?></strong><small><?=e(format_bytes($statsWeekBytes))?></small></div></div>
        <div class="col-md-4"><div class="stat h-100"><span>Descargas · 24 h</span><strong><?=number_format($statsTodayDownloads)?></strong><small>Descargas registradas</small></div></div>
      </div>
      <div class="card border-0 shadow-sm"><div class="card-body">
        <h2 class="h5"><i class="bi bi-bar-chart-line me-1"></i>Resumen por API</h2>
        <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>API</th><th>Peticiones</th><th>Estado</th></tr></thead><tbody>
        <?php foreach($statsApiTop as $sa): ?><tr><td><?=e($sa['name'])?></td><td><?=number_format((int)$sa['request_count'])?></td><td><?= $sa['revoked_at'] ? '<span class="badge text-bg-secondary">Revocada</span>' : '<span class="badge text-bg-success">Activa</span>' ?></td></tr><?php endforeach; ?>
        <?php if(!$statsApiTop): ?><tr><td colspan="3" class="text-body-secondary">No hay datos todavía.</td></tr><?php endif; ?></tbody></table></div>
      </div></div>
    </div>

    <div class="tab-pane <?= $activeTab==='health' ? 'is-active' : '' ?>" id="tab-health" role="tabpanel" tabindex="0">
      <div class="health-four-column-grid">
        <?php
        $uploadMaxLabel=(string)ini_get('upload_max_filesize');
        $postMaxLabel=(string)ini_get('post_max_size');
        $healthLabels=[
          'php'=>'PHP',
          'pdo_sqlite'=>'PDO SQLite / SQLite3',
          'storage'=>'Almacenamiento escribible',
          'database'=>'Base de datos principal',
          'logs'=>'Base de datos de Registro',
          'php_upload_limits'=>'Límites PHP de subida',
          'php_runtime'=>'Tiempo de ejecución PHP',
          'https'=>'HTTPS',
          'disk'=>'Espacio libre mínimo',
          'cron'=>'Cron de mantenimiento',
          'sqlite_version'=>'Versión SQLite',
          'database_sizes'=>'Tamaño de bases SQLite',
          'database_locations'=>'Ubicación de bases de datos',
          'opcache'=>'OPcache',
        ];
        $healthDetails=[
          'php'=>$health['php']?'PHP '.PHP_VERSION.' instalado.':'PHP '.PHP_VERSION.' detectado; se requiere PHP 8.2 o superior.',
          'pdo_sqlite'=>$health['pdo_sqlite']?'PDO SQLite y SQLite3 están habilitados.':'Falta PDO SQLite o SQLite3. Las bases SQLite no podrán operar.',
          'storage'=>$health['storage']?'El directorio de almacenamiento existe y PHP puede escribir en él.':'El directorio de almacenamiento no existe o PHP no tiene permisos de escritura.',
          'database'=>$health['database']?'El directorio de la base principal es escribible.':'El directorio de la base principal no es escribible.',
          'logs'=>$health['logs']?'El directorio de Registro es escribible y está separado de la base principal.':'El directorio de Registro no es escribible. La auditoría puede dejar de registrar eventos.',
          'php_upload_limits'=>($health['php_upload_limits']?'Compatible':'Insuficiente').': plataforma '.number_format($platformMaxMb).' MB · upload_max_filesize='.($uploadMaxLabel?:'no definido').' · post_max_size='.($postMaxLabel?:'no definido').' · max_file_uploads='.$currentMaxFileUploads.' · file_uploads='.$currentFileUploads.' · max_input_vars='.$currentMaxInputVars.'.',
          'php_runtime'=>($health['php_runtime']?'Compatible':'Por debajo del ajuste').': max_execution_time='.$currentExecTime.' s (configurado '.$configuredPhpExecution.' s) · max_input_time='.$currentInputTime.' s (configurado '.$configuredPhpInputTime.' s) · memory_limit='.($currentMemoryLimit?:'no definido').'.',
          'https'=>$health['https']?'La conexión actual usa HTTPS.':'La conexión actual no usa HTTPS. Habilita TLS/HTTPS antes de producción.',
          'disk'=>$health['disk']?'Hay '.format_bytes((int)$free).' libres; mínimo requerido: '.format_bytes($diskMinimumBytes).'.':'Hay '.($free===false?'un espacio libre no disponible':format_bytes((int)$free)).'; mínimo requerido: '.format_bytes($diskMinimumBytes).'.',
          'cron'=>!$cronHasRun?'Aún no se ha registrado una ejecución. El cron del hosting debe ejecutar cleanup.php; esta pantalla no puede consultar directamente el programador externo.':($health['cron']?'Última ejecución correcta: '.$healthLastCleanup.' · '.$cronLastDeleted.' archivo(s) eliminados · '.$cronLastDurationMs.' ms · origen: '.($cronLastSource==='manual'?'manual':'cron').'.':'Última ejecución: '.$healthLastCleanup.'. Estado: '.($cronLastStatus?:'desconocido').($cronStale?' · lleva más de 26 horas sin una ejecución correcta.':'').($cronLastError?' · Error: '.$cronLastError:'')),
          'sqlite_version'=>$health['sqlite_version']?'SQLite '.$sqliteVersion.' disponible.':'No se pudo consultar la versión de SQLite.',
          'database_locations'=>'Las bases SQLite están fuera de storage/: '.str_replace(DIRECTORY_SEPARATOR,'/',dirname($config['database_path'])).'.',
          'database_sizes'=>$health['database_sizes']?'Principal: '.format_bytes((int)$mainDbSize).' · Registro: '.format_bytes((int)$logsDbSize).'.':'No se pudo obtener el tamaño de una de las bases.',
          'opcache'=>$opcacheEnabled?'OPcache está habilitado en esta petición.':'OPcache no está disponible en esta petición (no necesariamente es un error).',
        ];
        ?>
        <?php foreach($health as $hk=>$ok): $unknown=($ok===null); ?>
          <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center gap-2">
                  <i class="bi <?=$unknown?'bi-info-circle-fill text-info':($ok?'bi-check-circle-fill text-success':'bi-exclamation-triangle-fill text-danger')?> fs-5"></i>
                  <strong><?=e($healthLabels[$hk]??$hk)?></strong>
                </div>
                <div class="small mt-2 <?=$unknown?'text-info-emphasis':($ok?'text-body-secondary':'text-danger-emphasis')?>">
                  <?=e($healthDetails[$hk]??'Sin información disponible.')?>
                </div>
                <?php if($hk==='php_upload_limits'): ?>
                  <div class="small text-body-secondary mt-2"><code>public/php.ini</code></div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="card border-0 shadow-sm mt-4">
        <div class="card-body d-flex align-items-center justify-content-between gap-3 flex-wrap">
          <div>
            <h2 class="h6 mb-1"><i class="bi bi-play-circle me-1"></i>Limpieza de mantenimiento</h2>
            <p class="text-body-secondary mb-0">Ejecuta directamente <code>cron/cleanup.php</code> desde Administración. La misma entrada se usa también por el cron del hosting y la ejecución queda registrada en el Registro.</p>
          </div>
          <form method="post" class="m-0" onsubmit="return confirm('¿Ejecutar la limpieza de mantenimiento ahora?');">
            <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
            <input type="hidden" name="action" value="run_cleanup_manual">
            <input type="hidden" name="admin_tab" value="health">
            <button type="submit" class="btn btn-primary"><i class="bi bi-play-circle me-1"></i>Ejecutar limpieza ahora</button>
          </form>
        </div>
      </div>

      <div class="card border-0 shadow-sm mt-3">
        <div class="card-body">
          <div class="small text-body-secondary mb-3">
            <strong>Diagnóstico PHP:</strong>
            PHP ini cargado: <code><?=e($loadedPhpIni!==''?basename($loadedPhpIni):'no identificado')?></code>
            · `.user.ini`: <code><?=e($userIniName!==''?$userIniName:'no configurado')?></code>
            · <?= $userIniReadable ? 'archivo legible' : 'archivo no legible/no presente' ?>
            <?php if($phpRuntimeLimitedByHost): ?>
              · <span class="text-warning">el hosting está imponiendo un valor efectivo inferior al configurado</span>
            <?php endif; ?>
            <?php $scannedIniCount=$scannedPhpIni!=='' ? count(array_filter(array_map('trim', preg_split('/[,\s]+/', $scannedPhpIni)))) : 0; ?>
            · INI adicionales detectados: <?=number_format($scannedIniCount)?>.
          </div>
          <h2 class="h5"><i class="bi bi-heart-pulse me-1"></i>Salud del sistema</h2>
          <p class="text-body-secondary mb-1">Versión: <strong><?=e($config['version'])?></strong></p>
          <p class="text-body-secondary mb-1">Mínimo de almacenamiento para una carga: <strong><?=e(format_bytes($diskMinimumBytes))?></strong></p>
          <p class="text-body-secondary mb-1">Zona horaria: <strong><?=e($config['timezone'])?></strong></p>
          <p class="text-body-secondary mb-0">Última limpieza: <strong><?=e($healthLastCleanup ?: 'No registrada')?></strong><?php if($cronHasRun && $cronLastStatus): ?> · Estado: <strong><?=e($cronLastStatus)?></strong><?php endif; ?></p>
        </div>
      </div>

      <div class="card border-0 shadow-sm mt-4">
        <div class="card-body">
          <h2 class="h5"><i class="bi bi-database-gear me-1"></i>Optimización de bases de datos</h2>
          <p class="text-body-secondary mb-3">
            Ejecuta mantenimiento sobre <code>portal.sqlite</code> y <code>logs.sqlite</code>.
            Se ejecuta <code>PRAGMA optimize</code> y después <code>VACUUM</code>, y finalmente se comprueba la integridad.
          </p>
          <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>La operación puede tardar y requiere espacio temporal adicional. No cierres la página mientras se ejecuta.</div>
          <form method="post" data-confirm="¿Optimizar ambas bases SQLite ahora? La operación puede tardar y requiere espacio temporal adicional.">
            <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
            <input type="hidden" name="action" value="optimize_databases">
            <input type="hidden" name="admin_tab" value="health">
            <button type="submit" class="btn btn-primary"><i class="bi bi-database-gear me-1"></i>Optimizar bases de datos</button>
          </form>
        </div>
      </div>
    </div>

    <div class="tab-pane <?= $activeTab==='register' ? 'is-active' : '' ?>" id="tab-register" role="tabpanel" tabindex="0">
      <div class="card border-0 shadow-sm"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
          <div><h2 class="h5 mb-1"><i class="bi bi-journal-text me-1"></i>Registro de seguridad y auditoría</h2><div class="small text-body-secondary">Los secretos, contraseñas, PIN, cookies y tokens nunca se almacenan en estos logs.</div></div>
          <form method="post"><input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>"><input type="hidden" name="action" value="verify_logs"><input type="hidden" name="admin_tab" value="register"><button type="submit" class="btn btn-outline-primary"><i class="bi bi-shield-check me-1"></i>Verificar integridad</button></form>
<form method="post" id="clearAuditForm" data-confirm-once="¿Eliminar TODOS los registros de auditoría? Esta acción no se puede deshacer y reiniciará la cadena de integridad." class="d-inline">
  <input type="hidden" name="csrf" value="<?=e(admin_csrf_token())?>">
  <input type="hidden" name="action" value="clear_all_logs">
  <input type="hidden" name="admin_tab" value="register">
  <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash3 me-1"></i>Eliminar todos los registros</button>
</form>
        </div>
        <?php if($logIntegrity!==null): ?><div class="alert <?=$logIntegrity['ok']?'alert-success':'alert-danger'?>"><i class="bi <?=$logIntegrity['ok']?'bi-check-circle':'bi-exclamation-triangle'?> me-1"></i><?=e($logIntegrity['ok']?'Integridad verificada: '.$logIntegrity['count'].' eventos.':('La cadena presenta un problema: '.implode(' | ',$logIntegrity['errors'])))?></div><?php endif; ?>
        <div class="row g-3 mb-3"><div class="col-md-4"><div class="stat h-100"><span>Total de eventos</span><strong><?=number_format($logCount)?></strong><small>retención configurada: <?=number_format((int)$config['log_retention_days'])?> días</small></div></div><div class="col-md-4"><div class="stat h-100"><span>Eventos de seguridad</span><strong><?=number_format($securityCount)?></strong><small>incluye autenticación y acciones sensibles</small></div></div><div class="col-md-4"><div class="stat h-100"><span>Resultados fallidos</span><strong><?=number_format($failureCount)?></strong><small>fallos, bloqueos y errores</small></div></div></div>
        <form method="get" class="row g-2 mb-3"><input type="hidden" name="admin_tab" value="register"><div class="col-lg-3"><select class="form-select" name="log_level"><option value="ALL">Todos los niveles</option><?php foreach(['DEBUG','INFO','NOTICE','WARNING','ERROR','CRITICAL'] as $lv): ?><option value="<?=e($lv)?>" <?=$logLevel===$lv?'selected':''?>><?=e($lv)?></option><?php endforeach; ?></select></div><div class="col-lg-3"><select class="form-select" name="log_security"><option value="all" <?=$logSecurity==='all'?'selected':''?>>Todos</option><option value="security" <?=$logSecurity==='security'?'selected':''?>>Solo seguridad</option></select></div><div class="col-lg-3"><input class="form-control" name="log_event" value="<?=e($logEvent)?>" placeholder="Tipo de evento"></div><div class="col-lg-3"><input class="form-control" name="log_q" value="<?=e($logQ)?>" placeholder="Buscar mensaje/actor/request ID"></div><div class="col-12 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-filter me-1"></i>Filtrar</button><a class="btn btn-outline-secondary" href="<?=e(app_base_url($config))?>/admin?admin_tab=register"><i class="bi bi-arrow-counterclockwise me-1"></i>Limpiar filtros</a></div></form>
        <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Fecha UTC</th><th>Nivel</th><th>Evento</th><th>Resultado</th><th>Actor</th><th>Origen</th><th>Descripción</th><th>Request ID</th></tr></thead><tbody>
        <?php foreach($auditLogs as $log): ?><tr><td class="text-nowrap small"><?=e($log['event_time'])?></td><td><span class="badge text-bg-<?=in_array($log['severity'],['ERROR','CRITICAL'])?'danger':($log['severity']==='WARNING'?'warning':'secondary')?>"><?=e($log['severity'])?></span></td><td><code><?=e($log['event_type'])?></code><?php if((int)$log['security_event']===1): ?><i class="bi bi-shield-lock-fill text-primary ms-1" title="Evento de seguridad"></i><?php endif; ?></td><td><?=e($log['outcome'])?></td><td><?=e((string)($log['actor_id']?:$log['actor_type']))?></td><td><?=e((string)$log['source_ip'])?></td><td class="text-break" style="min-width:260px"><?=e($log['message'])?></td><td><code><?=e($log['request_id'])?></code></td></tr><?php endforeach; ?>
        <?php if(!$auditLogs): ?><tr><td colspan="8" class="text-center text-body-secondary py-4">No hay eventos que coincidan con el filtro.</td></tr><?php endif; ?></tbody></table></div>
        <div class="small text-body-secondary mt-2">Los logs usan una cadena de hashes HMAC para detectar modificaciones o eliminaciones. El acceso a los logs también se registra.</div>
      </div></div>
    </div>
  </div>
</div>
<?php admin_layout_end();

function admin_layout_start(string $title,array $b,array $config): void { ?>
<!doctype html><html lang="es"><head>
<meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex"><script src="<?=e(app_asset_url($config, "assets/theme.js"))?>"></script><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> · <?=e($b['name'])?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"><link rel="stylesheet" href="<?=e(app_asset_url($config, "assets/app.css"))?>"></head><body><nav class="navbar border-bottom"><div class="container-fluid px-4 px-xxl-5 py-2"><a class="navbar-brand d-flex align-items-center gap-2 fw-semibold" href="<?=e(app_base_url($config))?>/"><?php if($b['logo']):?><img src="<?=e(app_asset_url($config,$b['logo']))?>" class="brand-logo" alt="Logo"><?php else:?><span class="brand-icon"><i class="bi bi-cloud-arrow-up-fill"></i></span><?php endif;?><span><?=e($b['name'])?></span></a><div class="dropdown"><button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-circle-half me-1"></i>Tema</button><ul class="dropdown-menu dropdown-menu-end"><li><button type="button" class="dropdown-item theme-choice" data-theme="light"><i class="bi bi-sun me-2" aria-hidden="true"></i>Claro</button></li><li><button type="button" class="dropdown-item theme-choice" data-theme="dark"><i class="bi bi-moon-stars me-2" aria-hidden="true"></i>Oscuro</button></li><li><button type="button" class="dropdown-item theme-choice" data-theme="auto"><i class="bi bi-circle-half me-2" aria-hidden="true"></i>Automático</button></li></ul></div></div></nav><main class="container-fluid px-4 px-xxl-5 py-4 admin-page">
<?php }
function admin_layout_end(): never { global $db,$config; ?>
</main><footer class="container-fluid px-4 px-xxl-5 text-center text-body-secondary small py-4"><?=e(get_footer_template($db,$config,true))?></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
<script nonce="<?=e(csp_nonce())?>">
(function(){
  const KEY='portal-admin-tab';
  const tabButtons=[...document.querySelectorAll('[data-admin-tab]')];
  const tabPanes=[...document.querySelectorAll('.admin-tabs .tab-pane')];

  function getStoredTab(){
    try { return localStorage.getItem(KEY) || ''; } catch(e) { return ''; }
  }

  function setStoredTab(name){
    try { localStorage.setItem(KEY,name); } catch(e) {}
  }

  function activateTab(name, pushUrl=true){
    const allowed=tabButtons.map(b=>b.dataset.adminTab);
    const safe=allowed.includes(name) ? name : 'stats';

    tabButtons.forEach(btn=>{
      const active=btn.dataset.adminTab===safe;
      btn.classList.toggle('active',active);
      btn.setAttribute('aria-selected',active?'true':'false');
    });

    tabPanes.forEach(pane=>{
      const active=pane.id===`tab-${safe}`;
      pane.classList.toggle('is-active',active);
      pane.classList.toggle('active',active);
      pane.hidden=!active;
      pane.setAttribute('aria-hidden',active?'false':'true');
    });

    setStoredTab(safe);

    if(pushUrl){
      const url=new URL(window.location.href);
      url.searchParams.set('admin_tab',safe);
      history.replaceState(null,'',url.toString());
    }
  }

  const root=document.querySelector('.admin-tabs');
  const serverTab=root?.dataset.initialTab || '';
  const urlTab=new URLSearchParams(window.location.search).get('admin_tab') || '';
  const initial=urlTab || serverTab || getStoredTab() || 'stats';
  activateTab(initial,false);

  tabButtons.forEach(btn=>{
    btn.addEventListener('click',()=>{
      activateTab(btn.dataset.adminTab,true);
    });
  });

  const maintenanceModeForm=document.getElementById('maintenance-mode-form');
  const maintenanceModeSwitch=document.getElementById('maintenance_mode');
  const maintenanceModeLabel=document.getElementById('maintenance-mode-label');
  if(maintenanceModeForm && maintenanceModeSwitch){
    const maintenanceModeValue=document.getElementById('maintenance_mode_value');
    maintenanceModeSwitch.addEventListener('change',()=>{
      if(maintenanceModeValue) maintenanceModeValue.value=maintenanceModeSwitch.checked ? '1' : '0';
      if(maintenanceModeLabel) maintenanceModeLabel.textContent='Guardando…';
      maintenanceModeForm.requestSubmit();
    });
  }

  document.querySelectorAll('form[data-confirm]').forEach(form=>{
    form.addEventListener('submit',e=>{
      if(form.dataset.confirmed==='1') return;
      if(!window.confirm(form.dataset.confirm)){ e.preventDefault(); return; }
      form.dataset.confirmed='1';
    });
  });


  document.querySelectorAll('form[data-confirm-once]').forEach(form=>{
    form.addEventListener('submit',e=>{
      if(form.dataset.confirmed==='1') return;
      if(form.dataset.confirming==='1'){ e.preventDefault(); return; }
      form.dataset.confirming='1';
      const ok=window.confirm(form.dataset.confirmOnce);
      form.dataset.confirming='0';
      if(!ok){ e.preventDefault(); return; }
      form.dataset.confirmed='1';
    });
  });


  document.querySelectorAll('form[method="post"]').forEach(form=>{
    form.addEventListener('submit',(e)=>{
      if(e.defaultPrevented) return;
      if(form.dataset.confirmed==='1' || form.dataset.submitting==='1') return;
      form.dataset.submitting='1';
      const btn=form.querySelector('button[type="submit"]');
      if(btn && !btn.disabled){
        btn.disabled=true;
        btn.dataset.originalHtml=btn.innerHTML;
        btn.innerHTML='<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Procesando…';
      }
    });
  });

  document.querySelectorAll('[data-copy-value]').forEach(btn=>{
    btn.addEventListener('click',async()=>{
      const old=btn.innerHTML;
      try{await navigator.clipboard.writeText(btn.dataset.copyValue);btn.innerHTML='<i class="bi bi-check2"></i>';}
      catch(e){}
      setTimeout(()=>btn.innerHTML=old,1200);
    });
  });

  
  document.querySelectorAll('[data-copy-target]').forEach(b=>b.addEventListener('click',async()=>{
    const i=document.getElementById(b.dataset.copyTarget); const old=b.innerHTML;
    try{await navigator.clipboard.writeText(i.value);b.innerHTML='<i class="bi bi-check2"></i>';}catch(e){i.select();document.execCommand('copy');b.innerHTML='<i class="bi bi-check2"></i>';}
    setTimeout(()=>b.innerHTML=old,1200);
  }));

  document.querySelectorAll('.copy-url').forEach(b=>b.addEventListener('click',async()=>{
    const i=document.getElementById(b.dataset.target);
    const old=b.innerHTML;
    try{await navigator.clipboard.writeText(i.value);b.innerHTML='<i class="bi bi-check2"></i>';}
    catch(e){i.select();document.execCommand('copy');b.innerHTML='<i class="bi bi-check2"></i>';}
    setTimeout(()=>b.innerHTML=old,1200);
  }));
})();
</script></body></html>
<?php exit; }
