<?php

declare(strict_types=1);

namespace Plugin\xhubio_invoice_api_xhub\Service;

use JTL\Plugin\Helper as PluginHelper;
use JTL\Plugin\PluginInterface;
use JTL\Shop;

/**
 * Static helper for resolving the plugin instance and its config from any
 * call site (hook handler, admin entrypoint, future cron worker). Centralises
 * the JTL-Shop plugin-loader call so we have ONE place to update if JTL-Shop
 * ever changes the resolution API again.
 *
 * The plugin ID matches the <PluginID> in info.xml — must stay in sync.
 */
final class PluginContext
{
    /** Plugin ID as declared in info.xml — single source of truth for lookups. */
    public const PLUGIN_ID = 'xhubio_invoice_api_xhub';

    /**
     * Resolve the running plugin instance.
     *
     * JTL-Shop V5 exposes `\JTL\Plugin\Helper` with a static
     * `getLoaderByPluginID(string, DbInterface, JTLCacheInterface)` factory
     * that returns a loader; calling `init($pluginId)` on the loader yields
     * the `\JTL\Plugin\PluginInterface` instance hydrated with the live
     * config. We deliberately avoid the older `getPluginById()` shortcut
     * because some V5 builds removed it.
     *
     * Returns null on any failure — callers MUST handle that path because
     * the helper can be invoked very early (before the plugin is fully
     * registered) or when the plugin is disabled.
     */
    public static function plugin(): ?PluginInterface
    {
        try {
            // JTL V5 high-level helper: handles legacy/modern loader selection
            // internally, returns the hydrated PluginInterface or null. Don't
            // use getLoaderByPluginID() — that expects an int kPlugin, not the
            // string cPluginID, and was a previous bug that left the hook
            // returning silently because the plugin couldn't be resolved.
            $plugin = PluginHelper::getPluginById(self::PLUGIN_ID);

            return $plugin instanceof PluginInterface ? $plugin : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Convenience: returns a `ConfigLoader` bound to this plugin instance,
     * or null when the plugin cannot be resolved.
     */
    public static function config(): ?ConfigLoader
    {
        $plugin = self::plugin();

        return null !== $plugin ? new ConfigLoader($plugin) : null;
    }

    /**
     * Returns the absolute filesystem path to the plugin's writable files
     * directory ("{plugin}/files/"). InvoiceFileStorage uses this to lay out
     * per-order subdirectories. Returns null when the plugin cannot be
     * resolved or the modern getPaths() API is missing on this JTL build.
     */
    public static function filesDir(): ?string
    {
        $plugin = self::plugin();
        if (null === $plugin) {
            return null;
        }

        try {
            // JTL V5 PluginInterface exposes getPaths()->getBasePath().
            $base = rtrim($plugin->getPaths()->getBasePath(), '/\\');

            return '' !== $base ? $base . '/files' : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
