<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class OdooOrderHook extends Module
{
    public function __construct()
    {
        $this->name = 'odooorderhook';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Francis Rosales';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Odoo Order Hook');
        $this->description = $this->l('Envía pedidos validados a Odoo vía webhook XML.');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('actionValidateOrder')
            && Configuration::updateValue('ODH_DRYRUN_ENABLED', '1')
            && Configuration::updateValue('ODH_WEBHOOK_URL', 'https://distri-latina.ch/prestashop_odoo_sync/public/webhook_sale.php');
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('ODH_DRYRUN_ENABLED')
            && Configuration::deleteByName('ODH_WEBHOOK_URL');
    }

    public function getContent()
    {
        if (Tools::isSubmit('submit_odooorderhook')) {
            $dryRun = Tools::getValue('ODH_DRYRUN_ENABLED') === '1' ? '1' : '0';
            $url = Tools::getValue('ODH_WEBHOOK_URL');
            Configuration::updateValue('ODH_DRYRUN_ENABLED', $dryRun);
            Configuration::updateValue('ODH_WEBHOOK_URL', $url);
            $this->context->controller->confirmations[] = $this->l('Configuración guardada correctamente.');
        }

        $enabled = Configuration::get('ODH_DRYRUN_ENABLED') === '1';
        $url = Configuration::get('ODH_WEBHOOK_URL');

        $form = '
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Configuración de sincronización Odoo</h3>
            </div>
            <div class="card-body">
                <form method="post">
                    <div class="form-group">
                        <label for="ODH_WEBHOOK_URL">URL del webhook:</label>
                        <input type="text" name="ODH_WEBHOOK_URL" id="ODH_WEBHOOK_URL" class="form-control" value="' . htmlspecialchars($url) . '" required>
                    </div>
                    <div class="form-group mt-3">
                        <label>Modo DryRun:</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="ODH_DRYRUN_ENABLED" value="1" ' . ($enabled ? 'checked' : '') . '>
                            <label class="form-check-label">Activado (solo trazabilidad)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="ODH_DRYRUN_ENABLED" value="0" ' . (!$enabled ? 'checked' : '') . '>
                            <label class="form-check-label">Desactivado (actualiza Odoo)</label>
                        </div>
                    </div>
                    <button type="submit" name="submit_odooorderhook" class="btn btn-primary mt-3">Guardar configuración</button>
                </form>
            </div>
        </div>';

        return $form;
    }

    public function hookActionValidateOrder($params)
    {
        $order = $params['order'];
        $products = $order->getProducts();
        $dryRun = Configuration::get('ODH_DRYRUN_ENABLED') === '1' ? 'true' : 'false';
        $webhookUrl = Configuration::get('ODH_WEBHOOK_URL');

        foreach ($products as $product) {
            $xml = new SimpleXMLElement('<sale/>');
            $xml->addChild('reference', $product['product_reference']);
            $xml->addChild('quantity_sold', (string) $product['product_quantity']);
            $xml->addChild('order_id', (string) $order->id);
            $xml->addChild('dryRun', $dryRun);

            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $xml->asXML());
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/xml']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                PrestaShopLogger::addLog("Webhook enviado OK para {$product['product_reference']}", 1, null, 'Order', $order->id, true);
            } else {
                PrestaShopLogger::addLog("Error al enviar webhook para {$product['product_reference']}. HTTP $httpCode", 3, null, 'Order', $order->id, true);
            }
        }
    }
}