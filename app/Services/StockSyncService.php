<?php
namespace App\Services;

use Psr\Log\LoggerInterface;

class StockSyncService
{
    private OdooClient $odoo;
    private PrestaShopClient $ps;
    private LoggerInterface $logger;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
        $this->ps = $odoo->prestashopClient;
        $this->logger = $odoo->getLogger();
    }

    public function syncAllStock(bool $dryRun = false): array
    {
        $productos = $this->odoo->getAllActiveProductsWithStock();
        if (!$productos) {
            $this->logger->error("No se pudo obtener productos desde Odoo.");
            return ['actualizados' => 0, 'errores' => 0];
        }

        $actualizados = 0;
        $errores = 0;
        $csvRows = [];

        foreach ($productos as $producto) {
            $referencia = $producto['default_code'] ?? null;
            $stockOdooOriginal = (int) $producto['qty_available'];

            if (!$referencia) continue;

            $stockPS = $this->ps->getStockByReference($referencia);
            if ($stockPS === null) continue;

            // 🔧 Normalizar: nunca enviar negativos
            $stockEnviado = $stockOdooOriginal < 0 ? 0 : $stockOdooOriginal;

            if ($stockEnviado !== (int) $stockPS) {
                if ($dryRun) {
                    $csvRows[] = [$referencia, $stockOdooOriginal, $stockPS, $stockEnviado, 'Simulado'];
                    $actualizados++;
                } else {
                    $resultado = $this->ps->updateStockByReference($referencia, $stockEnviado);
                    if ($resultado) {
                        $csvRows[] = [$referencia, $stockOdooOriginal, $stockPS, $stockEnviado, 'Actualizado'];
                        $actualizados++;
                    } else {
                        $csvRows[] = [$referencia, $stockOdooOriginal, $stockPS, $stockEnviado, 'Error'];
                        $errores++;
                        $this->odoo->logProductSyncError($producto, 'Error al actualizar stock en PrestaShop');
                    }
                }
            }
        }

        // Guardar CSV si hubo actividad
        if (!empty($csvRows)) {
            $timestamp = date('Ymd_His');
            $filename = "stock_sync_$timestamp.csv";
            $this->logToCsv($csvRows, $filename);
        }

        return ['actualizados' => $actualizados, 'errores' => $errores];
    }

    private function logToCsv(array $rows, string $filename): void
    {
        $path = __DIR__ . "/../../logs/$filename";
        $handle = fopen($path, 'w');

        // Encabezados
        fputcsv($handle, ['Referencia', 'Stock Odoo', 'Stock PrestaShop', 'Stock Enviado', 'Estado']);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
        $this->logger->info("📁 CSV generado", ['archivo' => $filename, 'total' => count($rows)]);
    }
}