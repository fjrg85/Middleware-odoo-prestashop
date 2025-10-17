<?php
namespace App\Services;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

class OdooClient {
    private $client;
    private $logger;
    private $env;
    private $enableDebug;
    public PrestaShopClient $prestashopClient;

    public function __construct(array $context) {
        $this->client = $context['http'];
        $this->logger = $context['logger'];
        $this->env = $context['env'];
        $this->prestashopClient = new PrestaShopClient($this->env, $this->logger);
        $this->enableDebug = $context['debug'] ?? false; // Activar con 'debug' => true en AppBootstrap
    }

    public function getLogger(): LoggerInterface {
        return $this->logger;
    }

    //validaciones
    public function checkOdooConfig(): bool {
        $requiredKeys = ['ODOO_URL', 'ODOO_DB', 'ODOO_USER', 'ODOO_PASS'];
        $missing = [];

        foreach ($requiredKeys as $key) {
            $value = $_ENV[$key] ?? '';
            if (trim($value) === '') {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            $this->logger->error("Configuración de Odoo incompleta: faltan variables", ['faltantes' => $missing]);
            return false;
        }

        if (!filter_var($_ENV['ODOO_URL'], FILTER_VALIDATE_URL)) {
            $this->logger->error("ODOO_URL inválida: debe ser una URL válida", ['ODOO_URL' => $_ENV['ODOO_URL']]);
            return false;
        }

        $this->logger->info("Configuración de Odoo verificada correctamente.");
        return true;
    }

    public function getOdooCredentials(): array|null {
        if (!$this->checkOdooConfig()) {
            $this->logger->error("No se pueden obtener credenciales: configuración inválida.");
            return null;
        }

        return [
            'url' => $_ENV['ODOO_URL'],
            'db' => $_ENV['ODOO_DB'],
            'user' => $_ENV['ODOO_USER'],
            'pass' => $_ENV['ODOO_PASS']
        ];
    }

    public function logProductSyncError(array $product, string $reason): void {
        $id = $product['id'] ?? 'sin_id';
        $name = $product['name'] ?? 'sin_nombre';

        $this->logger->error("❌ Error al sincronizar producto", [
            'id' => $id,
            'name' => $name,
            'reason' => $reason,
            'product' => $product
        ]);
    }

    public function getModifiedProductsSince(string $datetime, int $uid = 2): array|null {
        $creds = $this->getOdooCredentials();
        if (!$creds) {
            $this->logger->error("No se puede consultar productos: credenciales inválidas.");
            return null;
        }

        $url = rtrim($creds['url'], '/') . '/jsonrpc';
        $domain = [['write_date', '>=', $datetime]];

        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => [
                'service' => 'object',
                'method' => 'execute_kw',
                'args' => [
                    $creds['db'],
                    $uid,
                    $creds['pass'],
                    'product.product',
                    'search_read',
                    [ $domain ],
                    [
                        'fields' => ['id', 'name', 'default_code', 'list_price', 'qty_available', 'write_date'],
                        'limit' => 100
                    ]
                ]
            ]
        ];

        try {
            $response = $this->client->post($url, ['json' => $payload]);
            $body = json_decode($response->getBody(), true);

            if (isset($body['result'])) {
                $this->logger->info("Consulta incremental exitosa", [
                    'desde' => $datetime,
                    'count' => count($body['result'])
                ]);
                return $body['result'];
            }

            $this->logger->error("Error en respuesta incremental", ['response' => $body]);
            return null;

        } catch (\Exception $e) {
            $this->logger->error("Excepción en consulta incremental", ['error' => $e->getMessage()]);
            return null;
        }
    }
    
    public function getAllActiveProductsWithStock(int $uid = 2): array|null {
        $creds = $this->getOdooCredentials();
        if (!$creds) {
            $this->logger->error("No se puede consultar productos: credenciales inválidas.");
            return null;
        }

        $url = rtrim($creds['url'], '/') . '/jsonrpc';
        $domain = [
            //['detailed_type', '=', 'product'], // ✅ Odoo 18 usa detailed_type
            ['active', '=', true]
        ];

        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => [
                'service' => 'object',
                'method' => 'execute_kw',
                'args' => [
                    $creds['db'],
                    $uid,
                    $creds['pass'],
                    'product.product',
                    'search_read',
                    [ $domain ],
                    [
                        'fields' => ['id', 'name', 'default_code', 'qty_available'],
                        'limit' => 1000
                    ]
                ]
            ]
        ];

        try {
            $response = $this->client->post($url, ['json' => $payload]);
            $body = json_decode($response->getBody(), true);

            if (isset($body['result'])) {
                $this->logger->info("Consulta completa de productos activos", [
                    'count' => count($body['result'])
                ]);
                return $body['result'];
            }

            $this->logger->error("Error en respuesta completa", ['response' => $body]);
            return null;

        } catch (\Exception $e) {
            $this->logger->error("Excepción en consulta completa", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 🔄 Mapear campos de Odoo a un formato estándar para PrestaShopClient
     */
    public function mapProductFields(array $odooProduct): ?array {
        if (empty($odooProduct['default_code'])) {
            return null; // sin referencia no se puede mapear
        }

        return [
            'id'        => $odooProduct['id'] ?? null,
            'reference' => $odooProduct['default_code'] ?? null,
            'name'      => $odooProduct['name'] ?? '',
            'price'     => isset($odooProduct['list_price']) ? (float)$odooProduct['list_price'] : 0.0,
            'quantity'  => isset($odooProduct['qty_available']) ? (int)$odooProduct['qty_available'] : 0,
            'write_date'=> $odooProduct['write_date'] ?? null,
        ];
    }

    // -------------------------------
    // 🔍 Buscar producto por referencia
    // -------------------------------
    public function getProductByReference(string $reference, int $uid = 2): ?array {
        $creds = $this->getOdooCredentials();
        if (!$creds) {
            $this->logger->error("No se puede buscar producto: credenciales inválidas.");
            return null;
        }

        $url = rtrim($creds['url'], '/') . '/jsonrpc';
        $domain = [['default_code', '=', $reference]];

        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => [
                'service' => 'object',
                'method' => 'execute_kw',
                'args' => [
                    $creds['db'],
                    $uid,
                    $creds['pass'],
                    'product.product',
                    'search_read',
                    [ $domain ],
                    [
                        'fields' => ['id', 'default_code', 'qty_available'],
                        'limit' => 1
                    ]
                ]
            ]
        ];

        try {
            $response = $this->client->post($url, ['json' => $payload]);
            $body = json_decode($response->getBody(), true);

            if (isset($body['result']) && count($body['result']) > 0) {
                return $body['result'][0];
            }

            $this->logger->warning("Producto no encontrado en Odoo", ['reference' => $reference]);
            return null;

        } catch (\Exception $e) {
            $this->logger->error("Excepción al buscar producto en Odoo", [
                'reference' => $reference,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    // ---------------------------------
    // 🔄 Actualizar stock en Odoo
    // ---------------------------------
    public function updateProductStock(int $productId, float $newQty, int $uid = 2): bool {
        $creds = $this->getOdooCredentials();
        if (!$creds) {
            $this->logger->error("No se puede actualizar stock: credenciales inválidas.");
            return false;
        }

        $url = rtrim($creds['url'], '/') . '/jsonrpc';

        // Usamos el modelo stock.change.product.qty
        $vals = [
            'product_id'   => $productId,
            'new_quantity' => $newQty
        ];

        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => [
                'service' => 'object',
                'method' => 'execute_kw',
                'args' => [
                    $creds['db'],
                    $uid,
                    $creds['pass'],
                    'stock.change.product.qty',
                    'create',
                    [ $vals ]
                ]
            ]
        ];

        try {
            $response = $this->client->post($url, ['json' => $payload]);
            $body = json_decode($response->getBody(), true);

            if (isset($body['result'])) {
                $this->logger->info("Stock actualizado en Odoo", [
                    'product_id' => $productId,
                    'nuevo_stock' => $newQty
                ]);
                return true;
            }

            $this->logger->error("Error en respuesta de Odoo al actualizar stock", ['response' => $body]);
            return false;

        } catch (\Exception $e) {
            $this->logger->error("Excepción al actualizar stock en Odoo", [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 🚧 Método alternativo para futura integración:
     * Actualizar stock usando directamente el modelo stock.quant.
     * 
     * ⚠️ NOTA: Este método NO está en uso actualmente.
     * Se deja preparado para escenarios futuros donde se requiera
     * control de ubicaciones, lotes o multi-almacén.
     */
    public function updateProductStockWithQuant(int $productId, float $newQty, int $uid = 2): bool {
        $creds = $this->getOdooCredentials();
        if (!$creds) {
            $this->logger->error("No se puede actualizar stock (stock.quant): credenciales inválidas.");
            return false;
        }

        $url = rtrim($creds['url'], '/') . '/jsonrpc';

        // Aquí deberías identificar la ubicación (location_id) correcta.
        // En este ejemplo se asume la ubicación principal (id=1).
        $vals = [
            'product_id'  => $productId,
            'location_id' => 1, // ⚠️ Ajustar según tu configuración de Odoo
            'quantity'    => $newQty
        ];

        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => [
                'service' => 'object',
                'method' => 'execute_kw',
                'args' => [
                    $creds['db'],
                    $uid,
                    $creds['pass'],
                    'stock.quant',
                    'create',
                    [ $vals ]
                ]
            ]
        ];

        try {
            $response = $this->client->post($url, ['json' => $payload]);
            $body = json_decode($response->getBody(), true);

            if (isset($body['result'])) {
                $this->logger->info("Stock actualizado en Odoo vía stock.quant", [
                    'product_id' => $productId,
                    'nuevo_stock' => $newQty
                ]);
                return true;
            }

            $this->logger->error("Error en respuesta de Odoo al actualizar stock vía stock.quant", ['response' => $body]);
            return false;

        } catch (\Exception $e) {
            $this->logger->error("Excepción al actualizar stock vía stock.quant", [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

}