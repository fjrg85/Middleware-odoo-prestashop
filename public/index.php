<?php
use App\Bootstrap\AppBootstrap;
use App\Services\StockSyncService;
use App\Services\OdooClient;

require __DIR__ . '/../vendor/autoload.php';
date_default_timezone_set('Europe/Madrid');

// Detectar entorno: CLI o HTTP
$isCli = php_sapi_name() === 'cli';
$params = [];

if ($isCli) {
    // CLI: php index.php --task=stock --dryrun --range=2h
    $options = getopt('', ['task:', 'dryrun', 'range:']);
    $params['task'] = $options['task'] ?? 'odoo';
    $params['dryrun'] = isset($options['dryrun']);
    $params['range'] = $options['range'] ?? '1h';
} else {
    // HTTP: index.php?task=stock&dryrun=1&range=2h
    $params['task'] = $_GET['task'] ?? 'odoo';
    $params['dryrun'] = isset($_GET['dryrun']) && $_GET['dryrun'] === '1';
    $params['range'] = $_GET['range'] ?? '1h';

    // Forzar salida en texto plano
    header('Content-Type: text/plain; charset=utf-8');
}

// Inicializar contexto
$context = AppBootstrap::init();
$odoo = $context['odoo'] ?? new OdooClient($context);
$logger = $odoo->getLogger();

// Ejecutar según tarea
switch ($params['task']) {
    case 'odoo':
        // Pasar parámetros a sync_odoo_cron.php
        $_GET['dry-run'] = $params['dryrun'] ? '1' : '0';
        $_GET['range'] = $params['range'];
        require __DIR__ . '/sync_odoo_cron.php';
        break;

    case 'stock':
        $syncService = new StockSyncService($odoo);
        $resultado = $syncService->syncAllStock($params['dryrun']);

        echo "✅ Stock sincronizado: {$resultado['actualizados']} actualizados, {$resultado['errores']} con error\n";
        echo "📁 Revisa el CSV generado en logs/ para más detalles.\n";
        break;

    default:
        echo "❌ Tarea desconocida: '{$params['task']}'. Usa 'odoo' o 'stock'.\n";
        exit(1);
}