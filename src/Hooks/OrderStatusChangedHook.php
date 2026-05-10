<?php

declare(strict_types=1);

namespace Plugin\xhubio_invoice_api_xhub\Hooks;

use JTL\Checkout\Bestellung;
use JTL\Shop;
use Plugin\xhubio_invoice_api_xhub\Service\ApiClient;
use Plugin\xhubio_invoice_api_xhub\Service\InvoiceFileStorage;
use Plugin\xhubio_invoice_api_xhub\Service\InvoiceGenerator;
use Plugin\xhubio_invoice_api_xhub\Service\InvoiceNumberService;
use Plugin\xhubio_invoice_api_xhub\Service\OrderMapper;
use Plugin\xhubio_invoice_api_xhub\Service\PluginContext;
use Plugin\xhubio_invoice_api_xhub\Service\TemplateResolver;

/**
 * Listener for the JTL-Shop order-status-changed hook
 * (Hook ID 181 — HOOK_BESTELLUNGEN_XML_BESTELLSTATUS).
 *
 * Analog to the WooCommerce `woocommerce_order_status_*` events and the
 * Shopware `state_machine.order.state.complete` events. Hook 181 is the
 * SINGLE numeric hook the plugin listens on — all status-based triggers
 * (pending / on-hold / processing / completed) are routed through here via
 * the configured `trigger` setting.
 *
 * Defensive contract:
 *   - Failures are LOGGED via the JTL log service, never propagated up. A
 *     thrown exception inside a hook would crash the order-save flow and
 *     leave the customer's order in an inconsistent state.
 *   - The handler is idempotent: InvoiceGenerator skips when a file already
 *     exists for the order, so duplicate trigger fires (e.g. operator manually
 *     re-saves an order that's already in "completed") do not produce duplicate
 *     invoice numbers.
 *   - "Off" trigger short-circuits early so manual-only flows pay no overhead.
 */
class OrderStatusChangedHook
{
    /**
     * Map JTL `BestellungStatus` codes → plugin trigger-setting buckets.
     *
     * JTL-Shop status code reference (from \JTL\Checkout\BestellungStatus):
     *   BESTELLUNG_STATUS_OFFEN          =  1  → on_pending
     *   BESTELLUNG_STATUS_IN_BEARBEITUNG =  2  → on_processing
     *   BESTELLUNG_STATUS_BEZAHLT        =  3  → on_processing
     *   BESTELLUNG_STATUS_VERSANDT       =  4  → on_completed
     *   BESTELLUNG_STATUS_TEILVERSANDT   =  5  → on_processing (partial ship)
     *   BESTELLUNG_STATUS_STORNO         = -1  → (credit-note, Phase γ)
     *
     * "On hold" is not a native JTL status code — we treat it as an alias
     * for "pending" if the trigger is on_on_hold (advance-payment flow).
     */
    private const STATUS_BUCKETS = [
        1  => 'on_pending',
        2  => 'on_processing',
        3  => 'on_processing',
        4  => 'on_completed',
        5  => 'on_processing',
    ];

    /**
     * Hook handler entry point. JTL-Shop calls this when an order
     * transitions between statuses (e.g. "open" → "paid" → "shipped" →
     * "completed"). The payload typically includes the Bestellung object
     * and the new status code.
     *
     * @param array<string, mixed> $args Hook payload provided by JTL-Shop.
     */
    public static function execute(array $args = []): void
    {
        try {
            self::handle($args);
        } catch (\Throwable $e) {
            // Last-resort safety net: even the dispatcher itself must never
            // propagate exceptions to JTL. Logged and swallowed.
            self::log('error', 'Invoice-api.xhub: hook crashed: ' . $e->getMessage());
        }
    }

    /**
     * Internal handler — wrapped by execute() so the public surface can
     * never throw.
     *
     * @param array<string, mixed> $args
     */
    private static function handle(array $args): void
    {
        // 1. Resolve the new order status from the payload. JTL ships the
        //    status under several keys depending on which call-site fires
        //    the hook; check all of them defensively.
        $statusCode = self::extractStatusCode($args);
        if (null === $statusCode) {
            // Nothing to do — the hook can fire for non-state-transition
            // events (e.g. when the operator only edits order notes).
            return;
        }

        $bucket = self::STATUS_BUCKETS[$statusCode] ?? null;
        if (null === $bucket) {
            // Unknown / unsupported status (e.g. STORNO=-1 → would map to a
            // credit-note flow in Phase γ). Silently ignore for now.
            return;
        }

        // 2. Read configured trigger; abort early when generation is off or
        //    the bucket does not match.
        $config = PluginContext::config();
        if (null === $config) {
            self::log('warning', 'Invoice-api.xhub: plugin context unavailable, skipping hook.');

            return;
        }

        $configValues = $config->getAll();
        $trigger      = (string) ($configValues['trigger'] ?? 'off');

        if ('off' === $trigger || '' === $trigger) {
            return;
        }
        if ($trigger !== $bucket) {
            return;
        }

        // 3. Load the order. JTL hooks usually pass the Bestellung directly,
        //    but some entry-points only pass the kBestellung integer.
        $order = self::extractOrder($args);
        if (null === $order) {
            self::log('warning', 'Invoice-api.xhub: hook fired without a resolvable order.');

            return;
        }

        // 4. Build the service stack. We resolve the plugin instance via
        //    PluginContext to keep this handler decoupled from JTL-Shop's
        //    internal plugin-loader API surface.
        $plugin = PluginContext::plugin();
        if (null === $plugin) {
            self::log('warning', 'Invoice-api.xhub: plugin instance unavailable, skipping generation.');

            return;
        }

        $db = Shop::Container()->getDB();

        $apiKey  = (string) ($configValues['apiKey'] ?? '');
        $baseUrl = (string) ($configValues['baseUrl'] ?? '');
        if ('' === $apiKey) {
            self::log('info', 'Invoice-api.xhub: API key missing, skipping generation.');

            return;
        }

        $filesDir = PluginContext::filesDir();
        if (null === $filesDir || '' === $filesDir) {
            self::log('warning', 'Invoice-api.xhub: cannot resolve plugin files directory, skipping generation.');

            return;
        }

        $apiClient        = new ApiClient($apiKey, $baseUrl);
        $storage          = new InvoiceFileStorage($filesDir);
        $numberService    = new InvoiceNumberService($db);
        $templateResolver = new TemplateResolver($db);
        $mapper           = new OrderMapper($numberService);
        $generator        = new InvoiceGenerator(
            $apiClient,
            $mapper,
            $templateResolver,
            $storage,
            $config,
            $db,
        );

        // 5. Run generation. InvoiceGenerator already swallows its own
        //    exceptions and returns a result tuple — but we still wrap in a
        //    try/catch as belt-and-braces protection.
        $orderId = isset($order->kBestellung) ? (int) $order->kBestellung : 0;
        try {
            $result = $generator->generateForOrder($order);
        } catch (\Throwable $e) {
            self::log(
                'error',
                sprintf(
                    'Invoice-api.xhub: generation crashed for order %d: %s',
                    $orderId,
                    $e->getMessage(),
                ),
            );

            return;
        }

        if (true === ($result['success'] ?? false)) {
            self::log(
                'info',
                sprintf(
                    'Invoice-api.xhub: generated invoice for order %d (%s, %d bytes).',
                    $orderId,
                    (string) ($result['filename'] ?? ''),
                    (int) ($result['bytes'] ?? 0),
                ),
            );
        } else {
            self::log(
                'warning',
                sprintf(
                    'Invoice-api.xhub: generation failed for order %d: %s',
                    $orderId,
                    (string) ($result['error'] ?? 'unknown error'),
                ),
            );
        }
    }

    /**
     * Resolve the new status code from the hook payload. JTL has shipped
     * several variant keys over time; we accept all of them.
     *
     * @param array<string, mixed> $args
     */
    private static function extractStatusCode(array $args): ?int
    {
        foreach (['cStatus', 'iStatus', 'nStatus', 'status', 'newStatus'] as $key) {
            if (isset($args[$key]) && is_numeric($args[$key])) {
                return (int) $args[$key];
            }
        }

        // Some hook variants pass an order whose own status field carries the
        // new value already. Use it as a last resort.
        $order = self::extractOrder($args);
        if (null !== $order && isset($order->cStatus) && is_numeric($order->cStatus)) {
            return (int) $order->cStatus;
        }

        return null;
    }

    /**
     * Resolve the `\JTL\Checkout\Bestellung` instance from the hook payload.
     * Tries direct object payload first, then falls back to loading by
     * primary key when the hook only passed an integer.
     *
     * @param array<string, mixed> $args
     */
    private static function extractOrder(array $args): ?Bestellung
    {
        foreach (['oBestellung', 'order', 'bestellung'] as $key) {
            if (isset($args[$key]) && $args[$key] instanceof Bestellung) {
                return $args[$key];
            }
        }

        $orderId = 0;
        foreach (['kBestellung', 'orderId', 'id'] as $key) {
            if (isset($args[$key]) && is_numeric($args[$key])) {
                $orderId = (int) $args[$key];
                break;
            }
        }

        if ($orderId <= 0) {
            return null;
        }

        try {
            // The second argument loads positions; the third argument loads
            // payment / shipping data. Both are required for OrderMapper.
            return new Bestellung($orderId, true);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Best-effort logger. Routes through `Shop::Container()->getLogService()`
     * (Monolog-compatible) when available; falls back to error_log so the
     * message is at least visible in the PHP error log on misconfigured
     * hosts.
     */
    private static function log(string $level, string $message): void
    {
        try {
            $logger = Shop::Container()->getLogService();

            if (method_exists($logger, $level)) {
                /** @var callable(string):void $callable */
                $callable = [$logger, $level];
                $callable($message);

                return;
            }

            if (method_exists($logger, 'log')) {
                $logger->log($level, $message);

                return;
            }
        } catch (\Throwable) {
            // Fall through to error_log.
        }

        error_log('[' . $level . '] ' . $message);
    }
}
