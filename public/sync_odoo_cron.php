<?php
use App\Bootstrap\AppBootstrap;
use App\Services\OdooClient;

require __DIR__ . '/../vendor/autoload.php';
//date_default_timezone_set('Europe/Madrid');

// 📁 Ruta del archivo de sincronización persistente
$syncFile = __DIR__ . '/../logs/.sync_timestamp';

// 🧠 Interpretar rango como intervalo válido
function parseTimeRange(string $range): string {
    $range = trim($range);
    if (preg_match('/^(\d+)([hdm])$/', $range, $matches)) {
        $value = (int)$matches[1];
        $unit = $matches[2];
        switch ($unit) {
            case 'h': return "-$value hours";
            case 'd': return "-$value days";
            case 'm': return "-$value minutes";
        }
    }
    return "-1 hour"; // fallback seguro
}

// 🕒 Leer última fecha de sincronización
function getLastSyncTimestamp(string $path, string $fallbackRange): string {
    $dryRun = isset($_GET['dry-run']) && $_GET['dry-run'] === '1';
    $forceRange = isset($_GET['force-range']) && $_GET['force-range'] === '1';
    $now = time();
    $interval = parseTimeRange($fallbackRange);

    if ($dryRun || $forceRange || !file_exists($path)) {
        return date('Y-m-d H:i:s', strtotime($interval));
    }

    $stored = trim(file_get_contents($path));
    $storedTime = strtotime($stored);

    if (!$storedTime || $storedTime > $now) {
        error_log("⚠️ Timestamp inválido o futuro detectado en $path. Usando fallback de $fallbackRange.");
        return date('Y-m-d H:i:s', strtotime($interval));
    }

    return $stored;
}

// 🕓 Guardar nueva fecha de sincronización
function saveSyncTimestamp(string $path, string $timestamp): void {
    file_put_contents($path, $timestamp);
}

// 🏁 Detectar modo dry-run desde URL
$dryRun = isset($_GET['dry-run']) && $_GET['dry-run'] === '1';

// ⏱️ Detectar rango desde URL (por defecto: 1 hour)
$range = isset($_GET['range']) ? $_GET['range'] : '1h';
$interval = parseTimeRange($range);

// 🔧 Inicializar contexto y cliente
$context = AppBootstrap::init();
$odoo = new OdooClient($context);

// 🕒 Obtener fecha de corte
$desde = getLastSyncTimestamp($syncFile, $range);
$ahora = date('Y-m-d H:i:s');

// 🧪 Diagnóstico en pantalla
echo "🕒 Fecha actual del servidor (PHP): $ahora\n";
echo "📌 Rango recibido: $range → interpretado como '$interval'\n";
echo "📌 Fecha de corte calculada: $desde\n";

// 🔄 Consultar productos modificados desde esa fecha
$raw = $odoo->getModifiedProductsSince($desde);
if (!$raw || empty($raw)) {
    $odoo->getLogger()->info("Sin productos modificados desde $desde.");
    echo "⏳ Sin productos modificados desde $desde.\n";
    exit;
}

// 🧼 Transformar y filtrar productos válidos
$productos = array_filter(array_map([$odoo, 'mapProductFields'], $raw));

// 🚀 Sincronizar o simular
$exitos = 0;
$errores = 0;
$csvRows = [];

foreach ($productos as $producto) {
    $referencia     = $producto['reference'] ?? 'sin_ref';
    $precio         = $producto['price'] ?? 0;
    $cantidadOdoo   = $producto['quantity'] ?? 0;

    // 🔧 Normalizar: nunca enviar negativos
    $cantidadEnviada = $cantidadOdoo < 0 ? 0 : $cantidadOdoo;

    if ($dryRun) {
        $odoo->getLogger()->info("Simulación: se sincronizaría el producto", ['producto' => $producto]);
        $csvRows[] = [$referencia, $precio, $cantidadOdoo, $cantidadEnviada, 'Simulado'];
        $exitos++;
    } else {
        // Forzar cantidad normalizada en la sincronización
        $resultado = $odoo->prestashopClient->syncProductFromOdoo([
            ...$producto,
            'quantity' => $cantidadEnviada
        ]);
        if ($resultado) {
            $csvRows[] = [$referencia, $precio, $cantidadOdoo, $cantidadEnviada, 'Actualizado'];
            $exitos++;
        } else {
            $csvRows[] = [$referencia, $precio, $cantidadOdoo, $cantidadEnviada, 'Error'];
            $errores++;
            $odoo->logProductSyncError($producto, 'Error al sincronizar con PrestaShop');
        }
    }
}

// 🕓 Guardar nueva fecha de sincronización (solo si no es dry-run)
if (!$dryRun) {
    saveSyncTimestamp($syncFile, $ahora);
}

// 📁 Guardar CSV si hubo actividad
if (!empty($csvRows)) {
    $timestamp = date('Ymd_His');
    $filename = __DIR__ . "/../logs/odoo_sync_$timestamp.csv";
    $handle = fopen($filename, 'w');
    fputcsv($handle, ['Referencia', 'Precio Odoo', 'Cantidad Odoo', 'Cantidad Enviada', 'Estado']);
    foreach ($csvRows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);
    $odoo->getLogger()->info("📁 CSV generado", ['archivo' => $filename, 'total' => count($csvRows)]);
}

// 📊 Log final
$odoo->getLogger()->info("Tarea cron completada", [
    'modo' => $dryRun ? 'dry-run' : 'normal',
    'desde' => $desde,
    'hasta' => $ahora,
    'total' => count($productos),
    'exitos' => $exitos,
    'errores' => $errores
]);

echo "\n✅ Tarea cron completada en modo " . ($dryRun ? "dry-run" : "normal") . ": $exitos exitosos, $errores con error\n";
echo "📁 Revisa el CSV generado en logs/ para más detalles.\n";