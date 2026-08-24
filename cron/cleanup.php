<?php
declare(strict_types=1);

/**
 * Shared entry point for the maintenance cleanup.
 *
 * When called from Administration, the file is included and returns an array.
 * When executed by the hosting scheduler (PHP CLI), it prints the CLI result
 * and exits with an appropriate status code.
 */
if (!isset($db) || !isset($config)) {
    require dirname(__DIR__).'/app/bootstrap.php';
}

if (!function_exists('execute_cleanup_from_cron')) {
    function execute_cleanup_from_cron(PDO $db, array $config, string $actor = 'system', string $source = 'scheduled'): array
    {
        try {
            return run_cleanup_job($db, $config, $actor, $source);
        } catch (Throwable $e) {
            try {
                cron_heartbeat(
                    $db,
                    'error',
                    0,
                    0,
                    $e->getMessage(),
                    $source
                );
                audit_event(
                    $db,
                    $config,
                    'system_cleanup',
                    'ERROR',
                    true,
                    'error',
                    $actor,
                    null,
                    $source === 'manual'
                        ? 'La limpieza manual terminó con error.'
                        : 'La limpieza programada terminó con error.',
                    ['error'=>$e->getMessage(),'source'=>$source]
                );
            } catch (Throwable $ignored) {}
            throw $e;
        }
    }
}

/* Direct execution by the hosting cron. */
if (PHP_SAPI === 'cli') {
    $result = execute_cleanup_from_cron($db, $config, 'system', 'scheduled');

    echo "Portal de archivos cleanup: {$result['deleted']} archivo(s) eliminado(s). Estado: {$result['status']}.".PHP_EOL;
    if (!empty($result['errors'])) {
        echo "Errores: ".count($result['errors']).PHP_EOL;
    }

    exit($result['status'] === 'success' ? 0 : 1);
}
