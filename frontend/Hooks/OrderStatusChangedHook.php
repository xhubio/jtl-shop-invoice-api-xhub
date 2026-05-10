<?php declare(strict_types=1);

/**
 * JTL-Shop hook entrypoint stub.
 *
 * JTL-Shop's plugin validator (Validation/Items/Hooks.php) requires that
 * every <Hook> file lives below the plugin's frontend/ directory. The real
 * implementation is in src/Hooks/OrderStatusChangedHook.php — kept there
 * so the Composer PSR-4 autoload (src/) can resolve the FQCN. This stub
 * just forwards the require so the validator's file_exists() check passes.
 */
require_once __DIR__ . '/../../autoload.php';

// JTL's hook dispatcher invokes the listener via the class FQCN; the
// autoloader registered above resolves Plugin\xhubio_invoice_api_xhub\Hooks\
// OrderStatusChangedHook → src/Hooks/OrderStatusChangedHook.php on first ref.
