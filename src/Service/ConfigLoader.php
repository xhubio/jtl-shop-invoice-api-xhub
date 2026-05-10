<?php

declare(strict_types=1);

namespace Plugin\xhubio_invoice_api_xhub\Service;

use JTL\Plugin\PluginInterface;

/**
 * Thin adapter around `\JTL\Plugin\PluginInterface::getConfig()->getValue()`.
 *
 * The plugin settings declared in `info.xml` (`<Settings>` block) are exposed
 * to the rest of the plugin as a flat assoc array. Centralising the lookup
 * here keeps the OrderMapper / InvoiceGenerator / TemplateResolver free from
 * any direct JTL-plugin-API knowledge.
 *
 * Setting names mirror the `<ValueName>` entries in info.xml verbatim. A
 * change there must be reflected in `KEYS` below.
 */
final class ConfigLoader
{
    /**
     * The list of `<ValueName>` entries from info.xml. Keep in sync.
     */
    public const KEYS = [
        // API connection
        'apiKey',
        'baseUrl',
        // Document defaults
        'country',
        'format',
        'trigger',
        'attachToEmail',
        'paymentDueDays',
        // Numbering
        'numberFormat',
        'sequenceReset',
        // Seller
        'sellerName',
        'sellerVatId',
        'sellerStreet',
        'sellerPostalCode',
        'sellerCity',
        'sellerCountryCode',
        'sellerEmail',
        'sellerPhone',
        // Bank
        'sellerIban',
        'sellerBic',
        'sellerBankName',
        'sellerAccountHolder',
        // Country specific (DE)
        'defaultLeitwegId',
        // Template
        'templateId',
    ];

    public function __construct(
        private readonly PluginInterface $plugin,
    ) {
    }

    /**
     * Read a single setting by `<ValueName>`. Returns `$default` when the
     * setting is missing or empty.
     *
     * The JTL plugin config returns its value as a stdClass-like object on
     * some plugin builds; we coerce to scalar via getValue() and fall back
     * to the default when the SDK returns null.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $config = $this->plugin->getConfig();

        // PluginInterface::getConfig()->getValue($key) is the canonical path
        // in JTL-Shop V5. Older builds expose getValue() returning null; we
        // normalise to the supplied default in that case.
        try {
            $value = $config->getValue($key);
        } catch (\Throwable) {
            return $default;
        }

        return null !== $value && '' !== $value ? $value : $default;
    }

    /**
     * Materialise every known setting into an assoc array. Empty values are
     * preserved as empty strings (NOT defaults) so the OrderMapper can run
     * its own defensive `?? ''` chain without losing the distinction
     * between "configured empty" and "key absent".
     *
     * @return array<string,mixed>
     */
    public function getAll(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = $this->get($key, '');
        }

        return $out;
    }
}
