<?php declare(strict_types=1);

/**
 * Per-plugin PSR-4 autoloader.
 *
 * JTL-Shop V5 does NOT parse plugin composer.json autoload mappings, so we
 * register our own spl_autoload_register for the Plugin\xhubio_invoice_api_xhub\*
 * namespace. Every JTL entry point (Bootstrap.php, adminmenu/settings.php,
 * frontend/Hooks/*.php) MUST require_once this file as its first action so
 * the loader is live before any `use Plugin\xhubio_invoice_api_xhub\...`
 * reference is touched.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Plugin\\xhubio_invoice_api_xhub\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file     = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
