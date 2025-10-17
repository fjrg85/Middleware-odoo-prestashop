<?php
use App\Bootstrap\AppBootstrap;
use App\Services\OdooClient;

require __DIR__ . '/../vendor/autoload.php';

$context = AppBootstrap::init();
$odoo = new OdooClient($context);

// Leer entrada cruda
$input = file_get_contents('php://input');
if (!$input) {
    http_response_code(400);
    header('Content-Type: application/xml');
    echo "<response><error>No se recibió contenido</error></response>";
    exit;
}

// Parsear XML
libxml_use_internal_errors(true);
$xml = simplexml_load_string($input, "SimpleXMLElement", LIBXML_NOCDATA);
if ($xml === false) {
    http_response_code(400);
    header('Content-Type: application/xml');
    echo "<response><error>XML inválido</error></response>";
    exit;
}

// Convertir a array
$data = json_decode(json_encode($xml), true);

// Extraer datos
$referencia      = $data['reference'] ?? null;
$cantidadVendida = isset($data['quantity_sold']) ? (int)$data['quantity_sold'] : null;
$orderId         = $data['order_id'] ?? null;
$dryRun          = isset($data['dryRun']) && $data['dryRun'] === 'true';

if (!$referencia || $cantidadVendida === null) {
    http_response_code(400);
    header('Content-Type: application/xml');
    echo "<response><error>Datos incompletos</error></response>";
    exit;
}

// 🔍 Obtener stock actual en Odoo
$producto = $odoo->getProductByReference($referencia);
if (!$producto) {
    http_response_code(404);
    header('Content-Type: application/xml');
    echo "<response><error>Producto $referencia no encontrado en Odoo</error></response>";
    exit;
}

$stockOdoo  = (int) $producto['qty_available'];
$nuevoStock = $stockOdoo - $cantidadVendida;
if ($nuevoStock < 0) {
    $nuevoStock = 0;
}

// -----------------------------
// 📑 CSV de seguimiento
// -----------------------------
$csvFile = __DIR__ . '/../logs/webhook_sales.csv';
$writeHeader = !file_exists($csvFile);

$fp = fopen($csvFile, 'a');
if ($writeHeader) {
    fputcsv($fp, ['timestamp','order_id','reference','quantity_sold','stock_actual','stock_calculado','dryRun']);
}
fputcsv($fp, [date('Y-m-d H:i:s'), $orderId, $referencia, $cantidadVendida, $stockOdoo, $nuevoStock, $dryRun ? 'true' : 'false']);
fclose($fp);

// -----------------------------
// 🧪 Modo DryRun
// -----------------------------
if ($dryRun) {
    $odoo->getLogger()->info("DryRun: venta recibida desde PrestaShop", [
        'order_id'       => $orderId,
        'referencia'     => $referencia,
        'vendido'        => $cantidadVendida,
        'stock_actual'   => $stockOdoo,
        'stock_calculado'=> $nuevoStock
    ]);

    header('Content-Type: application/xml');
    echo "<response><status>dryrun</status><nuevo_stock>$nuevoStock</nuevo_stock></response>";
    exit;
}

// -----------------------------
// 🚀 Actualización real
// -----------------------------
$resultado = $odoo->updateProductStock($producto['id'], $nuevoStock);

header('Content-Type: application/xml');
if ($resultado) {
    $odoo->getLogger()->info("Stock actualizado desde PrestaShop", [
        'order_id'       => $orderId,
        'referencia'     => $referencia,
        'vendido'        => $cantidadVendida,
        'stock_anterior' => $stockOdoo,
        'stock_nuevo'    => $nuevoStock
    ]);
    echo "<response><status>ok</status><nuevo_stock>$nuevoStock</nuevo_stock></response>";
} else {
    http_response_code(500);
    echo "<response><error>No se pudo actualizar stock en Odoo</error></response>";
}