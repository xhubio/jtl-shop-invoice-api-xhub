<?php

declare(strict_types=1);

namespace Plugin\xhubio_invoice_api_xhub;

require_once __DIR__ . '/autoload.php';

use JTL\Events\Dispatcher;
use JTL\Plugin\Bootstrapper;
use JTL\Shop;

/**
 * Plugin entry point for "Invoice-api.xhub for JTL-Shop".
 *
 * Bridges JTL-Shop orders to the invoice-api.xhub.io e-invoicing service.
 * Currently supports generation of PDF, XRechnung and ZUGFeRD documents;
 * additional formats (Factur-X, FatturaPA, Facturae, ebInterface, UBL,
 * ISDOC, NAV) ship from Q3 2026 onwards and will appear automatically.
 *
 * Lifecycle responsibilities:
 *  - installed:    creates BOTH plugin tables — the sequence-counter table
 *                  used for atomic, gap-free invoice numbering and the meta
 *                  table that tracks per-order invoice files plus per-order
 *                  template overrides.
 *  - enabled:      no-op (services become available via the plugin loader).
 *  - disabled:     no-op (event subscribers are simply unregistered).
 *  - uninstalled:  drops BOTH plugin tables when $deleteData is true so no
 *                  plugin artefacts remain on the shop database.
 */
class Bootstrap extends Bootstrapper
{
    private const SEQUENCE_TABLE = 'xplugin_xhubio_invoice_api_xhub_seq';
    private const META_TABLE     = 'xplugin_xhubio_invoice_api_xhub_meta';

    /**
     * Runs on plugin install. Creates two tables:
     *
     *  1. xplugin_xhubio_invoice_api_xhub_seq — sequence counter for atomic,
     *     gap-free invoice numbering (Section 14 UStG-compliant). The
     *     INSERT ... ON DUPLICATE KEY UPDATE pattern in InvoiceNumberService
     *     uses this table for a single-statement increment that stays correct
     *     under concurrent generations.
     *
     *  2. xplugin_xhubio_invoice_api_xhub_meta — per-order metadata: stored
     *     filepath, filename, format, byte size, last error message, optional
     *     per-order template-ID override, and timestamps. Read by
     *     InvoiceGenerator (idempotency check) and TemplateResolver
     *     (per-order overrides).
     */
    public function installed()
    {
        parent::installed();

        $db  = Shop::Container()->getDB();
        $pdo = $db->getPDO();

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::SEQUENCE_TABLE . '` (
                `period`     VARCHAR(20)         NOT NULL,
                `current`    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                `updated_at` DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`period`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::META_TABLE . '` (
                `order_id`             INT(10) UNSIGNED NOT NULL,
                `filepath`             VARCHAR(500)     DEFAULT NULL,
                `filename`             VARCHAR(255)     DEFAULT NULL,
                `format`               VARCHAR(50)      DEFAULT NULL,
                `bytes`                INT(10) UNSIGNED DEFAULT 0,
                `last_error`           TEXT             DEFAULT NULL,
                `template_id_override` VARCHAR(100)     DEFAULT NULL,
                `template_id_used`     VARCHAR(100)     DEFAULT NULL,
                `api_hash`             VARCHAR(128)     DEFAULT NULL,
                `generated_at`         DATETIME         DEFAULT NULL,
                `updated_at`           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
        );

        // Idempotently add diagnostic columns for installations created before
        // these columns existed in the schema. CREATE TABLE IF NOT EXISTS above
        // is a no-op for existing tables, so a separate ALTER is required.
        // Idempotency-cache uses `template_id_used` to invalidate when the
        // operator changes the configured templateId; `api_hash` is surfaced
        // in the History table so operators can confirm content actually
        // changed run-to-run.
        foreach (
            [
                ['template_id_used', 'VARCHAR(100) DEFAULT NULL', '`template_id_override`'],
                ['api_hash',         'VARCHAR(128) DEFAULT NULL', '`template_id_used`'],
            ] as [$col, $def, $after]
        ) {
            try {
                $exists = $pdo->query(
                    'SELECT 1 FROM information_schema.COLUMNS '
                    . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::META_TABLE . "' "
                    . "AND COLUMN_NAME = '" . $col . "'"
                )->fetchColumn();
                if (!$exists) {
                    $pdo->exec(
                        'ALTER TABLE `' . self::META_TABLE . '` '
                        . 'ADD COLUMN `' . $col . '` ' . $def . ' AFTER ' . $after
                    );
                }
            } catch (\Throwable) {
                // best-effort — diagnostic columns are purely additive; the
                // plugin remains functional without them.
            }
        }
    }

    /**
     * Runs on plugin update (when the plugin version is bumped). Each
     * statement here MUST be idempotent (CREATE TABLE IF NOT EXISTS, ALTER
     * TABLE ... ADD COLUMN IF NOT EXISTS, etc.) so reinstalls and partial
     * upgrades stay safe.
     */
    public function updated($oldVersion, $newVersion)
    {
        parent::updated($oldVersion, $newVersion);

        // Re-run the install DDL: CREATE TABLE IF NOT EXISTS is idempotent
        // and ensures both tables exist even when an older build only created
        // the sequence table.
        $this->installed();
    }

    /**
     * Runs when the operator enables the plugin from the JTL-Shop admin.
     * The plugin loader registers our hook files automatically based on the
     * declarations in info.xml, so there is nothing to do here.
     */
    public function enabled()
    {
        parent::enabled();
        // No-op: hook files are wired automatically via info.xml.
    }

    /**
     * Runs when the operator disables the plugin. Hooks are unregistered
     * automatically by the plugin loader, so we have nothing to do.
     */
    public function disabled()
    {
        parent::disabled();
        // No-op: hooks are unregistered automatically.
    }

    /**
     * Runs on plugin uninstall. Drops BOTH plugin tables (sequence + meta)
     * when the operator opted into a destructive uninstall via $deleteData.
     * Settings stored under the JTL-Shop plugin-config table are removed by
     * JTL-Shop itself.
     */
    public function uninstalled(bool $deleteData = true)
    {
        parent::uninstalled($deleteData);

        if (!$deleteData) {
            return;
        }

        $pdo = Shop::Container()->getDB()->getPDO();
        $pdo->exec('DROP TABLE IF EXISTS `' . self::META_TABLE . '`');
        $pdo->exec('DROP TABLE IF EXISTS `' . self::SEQUENCE_TABLE . '`');
    }

    /**
     * Hook for registering listeners against the JTL-Shop event dispatcher.
     *
     * Hook 181 (HOOK_BESTELLUNGEN_XML_BESTELLSTATUS) is wired declaratively via
     * info.xml — JTL-Shop's plugin loader instantiates src/Hooks/OrderStatusChangedHook.php
     * automatically, so we do NOT need to call $dispatcher->hookInto(181, ...) here.
     *
     * Reserved for future event subscriptions: from JTL-Shop 5.2.0 onwards
     * the dispatcher offers $dispatcher->hookInto(\HOOK_*, $callable, $priority)
     * as a fluent alternative to the legacy 'shop.hook.<ID>' string form
     * (see https://jtl-shop-mkdocs.readthedocs.io/de/latest/shop_plugins/bootstrapping.html).
     *
     * @param Dispatcher $dispatcher
     */
    public function boot(Dispatcher $dispatcher)
    {
        parent::boot($dispatcher);
    }
}
