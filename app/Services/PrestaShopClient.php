<?php
namespace App\Services;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

class PrestaShopClient {
    private Client $http;
    private string $apiKey;
    private string $baseUrl;
    private LoggerInterface $logger;

    public function __construct(array $config, LoggerInterface $logger) {
        $this->apiKey = $config['prestashop_api_key'];
        $this->baseUrl = rtrim($config['prestashop_base_url'], '/');
        $this->logger = $logger;

        $this->http = new Client([
            'base_uri' => $this->baseUrl,
            'auth' => [$this->apiKey, ''],
            'headers' => ['Accept' => 'application/json']
        ]);
    }
    public function getProductIdByReference(string $reference): ?int {
        try {
            $response = $this->http->get('/api/products', [
                'query' => [
                    'filter[reference]' => $reference,
                    'display' => '[id]'
                ],
                'headers' => [
                    'Accept' => 'application/xml'
                ]
            ]);

            $xml = new \SimpleXMLElement((string) $response->getBody());

            if (isset($xml->products->product->id)) {
                return (int) $xml->products->product->id;
            }

            return null;

        } catch (\Throwable $e) {
            $this->logger->error("Error al buscar producto por referencia", [
                'reference' => $reference,
                'exception' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function updateProductPrice(int $id, float $price): bool {
        try {
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $dom->formatOutput = true;

            $prestashop = $dom->createElement('prestashop');
            $product = $dom->createElement('product');
            $prestashop->appendChild($product);
            $dom->appendChild($prestashop);

            $idNode = $dom->createElement('id');
            $idNode->appendChild($dom->createCDATASection((string) $id));
            $product->appendChild($idNode);

            $priceNode = $dom->createElement('price');
            $priceNode->appendChild($dom->createCDATASection((string) $price));
            $product->appendChild($priceNode);

            $xml = $dom->saveXML();

            $response = $this->http->patch("/api/products/$id", [
                'headers' => [
                    'Content-Type' => 'application/xml',
                    'Accept' => 'application/xml'
                ],
                'body' => $xml
            ]);

            $this->logger->info("✅ Precio actualizado en PrestaShop", [
                'id' => $id,
                'price' => $price,
                'xml' => $xml,
                'response' => (string) $response->getBody()
            ]);

            return true;

        } catch (\Throwable $e) {
            $responseBody = 'Sin respuesta';
            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->getResponse()) {
                $responseBody = $e->getResponse()->getBody()->__toString();
            }
            $this->logger->error("❌ Error con respuesta", ['body' => $responseBody]);

            $this->logger->error("❌ Error al actualizar precio en PrestaShop", [
                'id' => $id,
                'price' => $price,
                'exception' => $e->getMessage()
            ]);
            return false;
        }
    }
    public function getStockAvailableId(int $productId): ?int {
        try {
            $response = $this->http->get("/api/products/$productId", [
                'headers' => ['Accept' => 'application/xml']
            ]);

            $xml = new \SimpleXMLElement((string) $response->getBody());

            $stockNode = $xml->product->associations->stock_availables->stock_available ?? null;

            if ($stockNode && isset($stockNode->id)) {
                return (int) $stockNode->id;
            }

            return null;

        } catch (\Throwable $e) {
            $this->logger->error("Error al obtener stock_available ID", [
                'product_id' => $productId,
                'exception' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function updateProductQuantity(int $stockId, int $productId, int $quantity): bool {
        try {
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $dom->formatOutput = true;

            $prestashop = $dom->createElement('prestashop');
            $stock = $dom->createElement('stock_available');
            $prestashop->appendChild($stock);
            $dom->appendChild($prestashop);

            $idNode = $dom->createElement('id');
            $idNode->appendChild($dom->createCDATASection((string) $stockId));
            $stock->appendChild($idNode);

            $productNode = $dom->createElement('id_product');
            $productNode->appendChild($dom->createCDATASection((string) $productId));
            $stock->appendChild($productNode);

            $quantityNode = $dom->createElement('quantity');
            $quantityNode->appendChild($dom->createCDATASection((string) $quantity));
            $stock->appendChild($quantityNode);

            $xml = $dom->saveXML();

            $response = $this->http->patch("/api/stock_availables/$stockId", [
                'headers' => [
                    'Content-Type' => 'application/xml',
                    'Accept' => 'application/xml'
                ],
                'body' => $xml
            ]);

            $this->logger->info("✅ Cantidad actualizada en PrestaShop", [
                'stock_id' => $stockId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'xml' => $xml,
                'response' => (string) $response->getBody()
            ]);

            return true;

        } catch (\Throwable $e) {
            $responseBody = 'Sin respuesta';
            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->getResponse()) {
                $responseBody = $e->getResponse()->getBody()->__toString();
            }
            $this->logger->error("❌ Error con respuesta", ['body' => $responseBody]);

            $this->logger->error("❌ Error al actualizar cantidad en PrestaShop", [
                'stock_id' => $stockId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'exception' => $e->getMessage()
            ]);
            return false;
        }
    }
    public function syncProductFromOdoo(array $data): bool {
        $reference = $data['reference'] ?? null;
        $price = $data['price'] ?? null;
        $quantity = $data['quantity'] ?? null;

        if (!$reference || $price === null || $quantity === null) {
            $this->logger->warning("Datos incompletos para sincronización", ['data' => $data]);
            return false;
        }

        $productId = $this->getProductIdByReference($reference);
        if (!$productId) {
            $this->logger->warning("Producto no encontrado en PrestaShop", ['reference' => $reference]);
            return false;
        }

        $priceUpdated = $this->updateProductPrice($productId, $price);

        $stockId = $this->getStockAvailableId($productId);
        if (!$stockId) {
            $this->logger->warning("Stock ID no encontrado para producto", ['product_id' => $productId]);
            return false;
        }

        $quantityUpdated = $this->updateProductQuantity($stockId, $productId, $quantity);

        $success = $priceUpdated && $quantityUpdated;

        $this->logger->info("Sincronización completada", [
            'reference' => $reference,
            'product_id' => $productId,
            'stock_id' => $stockId,
            'price' => $price,
            'quantity' => $quantity,
            'success' => $success
        ]);

        return $success;
    }    
    public function getStockByReference(string $reference): ?int {
        try {
            // Paso 1: obtener el ID del producto por referencia
            $productId = $this->getProductIdByReference($reference);
            if (!$productId) {
                $this->logger->warning("No se encontró producto con referencia", ['reference' => $reference]);
                return null;
            }

            // Paso 2: consultar stock_availables filtrando por id_product
            $response = $this->http->get('/api/stock_availables', [
                'query' => [
                    'filter[id_product]' => $productId,
                    'display' => '[quantity]'
                ],
                'headers' => [
                    'Accept' => 'application/xml'
                ]
            ]);

            $xml = new \SimpleXMLElement((string) $response->getBody());

            if (isset($xml->stock_availables->stock_available->quantity)) {
                return (int) $xml->stock_availables->stock_available->quantity;
            }

            $this->logger->warning("No se encontró stock para producto", ['product_id' => $productId]);
            return null;

        } catch (\Throwable $e) {
            $this->logger->error("Error al obtener stock por referencia", [
                'reference' => $reference,
                'exception' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function updateStockByReference(string $reference, float $newQty): bool {
        try {
            // Paso 1: obtener el ID del producto por referencia
            $productId = $this->getProductIdByReference($reference);
            if (!$productId) {
                $this->logger->warning("No se encontró producto para actualizar stock", ['reference' => $reference]);
                return false;
            }

            // Paso 2: obtener el ID del stock_available asociado
            $response = $this->http->get('/api/stock_availables', [
                'query' => [
                    'filter[id_product]' => $productId,
                    'display' => '[id]'
                ],
                'headers' => [
                    'Accept' => 'application/xml'
                ]
            ]);

            $xml = new \SimpleXMLElement((string) $response->getBody());
            if (!isset($xml->stock_availables->stock_available->id)) {
                $this->logger->warning("No se encontró stock_available para producto", ['product_id' => $productId]);
                return false;
            }

            $stockId = (int) $xml->stock_availables->stock_available->id;

            // Paso 3: construir XML para actualizar cantidad
            $stockXml = new \SimpleXMLElement('<stock_available></stock_available>');
            $stockXml->addChild('quantity', (string) $newQty);

            // Paso 4: enviar PATCH a PrestaShop
            $patchResponse = $this->http->patch("/api/stock_availables/{$stockId}", [
                'headers' => [
                    'Accept' => 'application/xml',
                    'Content-Type' => 'application/xml'
                ],
                'body' => $stockXml->asXML()
            ]);

            if ($patchResponse->getStatusCode() === 200) {
                $this->logger->info("Stock actualizado correctamente", [
                    'reference' => $reference,
                    'product_id' => $productId,
                    'stock_id' => $stockId,
                    'nuevo_stock' => $newQty
                ]);
                return true;
            }

            $this->logger->error("Error HTTP al actualizar stock", [
                'status' => $patchResponse->getStatusCode(),
                'reference' => $reference
            ]);
            return false;

        } catch (\Throwable $e) {
            $this->logger->error("Excepción al actualizar stock", [
                'reference' => $reference,
                'exception' => $e->getMessage()
            ]);
            return false;
        }
    }
}