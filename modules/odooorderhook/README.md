# Odoo Order Hook

Este módulo permite enviar pedidos validados desde PrestaShop a un middleware externo vía webhook XML, con trazabilidad y control de ejecución (`dryRun`) configurable desde el backoffice.

## 📦 Características

- Se engancha al hook `actionValidateOrder`.
- Envía un XML por cada producto vendido al endpoint `webhook_sale.php`.
- Incluye los campos: `reference`, `quantity_sold`, `order_id`, `dryRun`.
- Soporte para modo `dryRun` activable desde el panel de configuración.
- Registra logs en PrestaShop (`PrestaShopLogger`) para cada envío.

## 🔧 Instalación

1. Copiar la carpeta `odooorderhook` en `/modules/`.
2. Ir al backoffice → Módulos → Buscar “Odoo Order Hook” → Instalar.
3. Ir a “Configurar” para activar o desactivar el modo `dryRun`.

## 🔄 Flujo de sincronización

1. Se valida un pedido en PrestaShop.
2. El módulo construye un XML por cada producto vendido.
3. El XML se envía vía `cURL` al endpoint configurado (`webhook_sale.php`).
4. El middleware responde con `<status>ok</status>` o `<status>dryrun</status>`.
5. El módulo registra el resultado en el log de PrestaShop.

## 🧩 Requisitos

- PrestaShop 9.x
- PHP 8.4 o superior
- Middleware compatible con XML vía POST

## ✍️ Autor

Francis — Arquitecto de integración y automatización Odoo–PrestaShop

## 📄 Licencia

MIT (o la que prefieras)

## 🔗 Endpoint del webhook

`https://distri-latina.ch/prestashop_odoo_sync/public/webhook_sale.php`

## 🧪 Pruebas

Puedes probar el webhook manualmente con Postman:

```xml
<sale>
  <reference>ABC123</reference>
  <quantity_sold>2</quantity_sold>
  <order_id>4567</order_id>
  <dryRun>true</dryRun>
</sale>