<?php
use App\Bootstrap\AppBootstrap;
use App\Services\StockSyncService;

require __DIR__ . '/../vendor/autoload.php';
date_default_timezone_set('Europe/Madrid');

$context = AppBootstrap::init();
$odoo = $context['odoo']; // instancia de OdooClient
$syncService = new StockSyncService($odoo);

$resultado = $syncService->syncAllStock();
echo "✅ Stock sincronizado: {$resultado['actualizados']} actualizados, {$resultado['errores']} con error\n";