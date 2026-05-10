<?php

declare(strict_types=1);

namespace Plugin\xhubio_invoice_api_xhub\Service;

use JTL\Checkout\Bestellung;
use JTL\DB\DbInterface;

/**
 * Resolves the invoice-api.xhub PDF template id for a given JTL order.
 *
 * Resolution order (highest priority first):
 *   1. Per-order override stored in the plugin meta table
 *      (`xplugin_xhubio_invoice_api_xhub_meta`, key `template_id_override`).
 *      Lets shop operators override the default template for a specific
 *      order without changing global plugin settings — mirrors the
 *      Shopware/WooCommerce convention.
 *   2. Plugin configuration default (`templateId` from info.xml settings).
 *
 * Returns null when no template is configured at any level. The caller
 * (ApiClient::generate) then omits the `templateId` field from the request
 * body and the API uses its built-in standard template.
 *
 * Templates are format-specific in the API: a template designed for
 * XRechnung will not work for ZUGFeRD or PDF. Validation against the
 * selected format happens server-side; the resolver intentionally does not
 * try to second-guess that.
 */
final class TemplateResolver
{
    private const META_TABLE = 'xplugin_xhubio_invoice_api_xhub_meta';

    public function __construct(
        private readonly DbInterface $db,
    ) {
    }

    /**
     * @param array<string,mixed> $config Resolved plugin config (assoc array
     *                                    matching ConfigLoader::KEYS).
     */
    public function resolve(Bestellung $order, array $config): ?string
    {
        $orderId = isset($order->kBestellung) ? (int) $order->kBestellung : 0;

        if ($orderId > 0) {
            $override = $this->loadOverride($orderId);
            if (null !== $override && '' !== $override) {
                return $override;
            }
        }

        $default = isset($config['templateId']) ? trim((string) $config['templateId']) : '';

        return '' !== $default ? $default : null;
    }

    /**
     * Read the per-order `template_id_override` from the meta table. Silently
     * returns null on any DB error so a corrupted meta row never blocks
     * generation — the operator can always re-edit the override.
     *
     * NOTE: the meta table is created by the next agent (Phase β.B); see the
     * TODO comment in InvoiceGenerator.php for the DDL. Until that lands,
     * this lookup will throw a "table doesn't exist" — caught and ignored.
     */
    private function loadOverride(int $orderId): ?string
    {
        try {
            $stmt = $this->db->getPDO()->prepare(
                'SELECT `template_id_override` FROM `' . self::META_TABLE . '` WHERE `order_id` = :order_id LIMIT 1',
            );
            $stmt->execute(['order_id' => $orderId]);
            $value = $stmt->fetchColumn();

            if (false === $value || null === $value) {
                return null;
            }

            return trim((string) $value);
        } catch (\Throwable) {
            // Meta table may not exist yet (Phase β.B will create it). Treat
            // as "no override" and let global config win.
            return null;
        }
    }
}
