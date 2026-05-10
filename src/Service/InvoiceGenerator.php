<?php

declare(strict_types=1);

namespace Plugin\xhubio_invoice_api_xhub\Service;

use JTL\Checkout\Bestellung;
use JTL\DB\DbInterface;
use RuntimeException;

/**
 * Orchestrator for invoice generation in the JTL-Shop plugin.
 *
 * Single entry-point used by both the order-status hook (auto generation)
 * and the future admin "Regenerate" button (manual generation):
 *
 *     $result = $generator->generateForOrder($order);
 *
 * Steps:
 *   1. Load resolved config from ConfigLoader.
 *   2. Skip when the order has no positions (BR-16) — returns success=false
 *      with a non-error message; orchestrator callers should NOT treat this
 *      as a failure.
 *   3. Map order → invoice JSON via OrderMapper.
 *   4. Resolve template id via TemplateResolver.
 *   5. Call ApiClient::generate.
 *   6. Base64-decode `data` from the response.
 *   7. Persist file bytes via InvoiceFileStorage.
 *   8. Persist filename, filepath, last_error to the meta table.
 *
 * Idempotency: if a non-empty `filepath` already exists for the order in
 * the meta table AND the referenced file is still on disk, generateForOrder
 * returns early with success=true and the existing filename + filepath.
 *
 * Error path: on any RuntimeException (from mapping, API or file I/O), the
 * meta row is updated with `last_error`, the method returns success=false
 * with the error message, and no exception bubbles to the caller.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * TODO (Phase β.B integration agent): add the meta-table DDL below to
 * `Bootstrap::installed()` and add the matching DROP to `uninstalled()`.
 * The Service layer assumes the table exists; until then the loadMeta /
 * persistMeta methods catch DBALException-like errors and degrade
 * gracefully, but persistence will be lost.
 *
 *   CREATE TABLE IF NOT EXISTS `xplugin_xhubio_invoice_api_xhub_meta` (
 *       `order_id`             INT UNSIGNED NOT NULL,
 *       `filename`             VARCHAR(255) NULL,
 *       `filepath`             VARCHAR(512) NULL,
 *       `last_error`           TEXT NULL,
 *       `template_id_override` VARCHAR(255) NULL,
 *       `generated_at`         DATETIME NULL,
 *       PRIMARY KEY (`order_id`)
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 * ─────────────────────────────────────────────────────────────────────────
 */
final class InvoiceGenerator
{
    private const META_TABLE = 'xplugin_xhubio_invoice_api_xhub_meta';

    public function __construct(
        private readonly ApiClient $client,
        private readonly OrderMapper $mapper,
        private readonly TemplateResolver $templateResolver,
        private readonly InvoiceFileStorage $storage,
        private readonly ConfigLoader $config,
        private readonly DbInterface $db,
    ) {
    }

    /**
     * Generate (or skip / re-use existing) an invoice for a JTL order.
     *
     * When `$force` is true the idempotency cache is bypassed and any prior
     * artefact (file + meta-row) is wiped before regeneration. The auto-
     * trigger (Hook 181 dispatcher) always passes false to stay idempotent
     * across status-transition replays; the admin "Generate now" form passes
     * true so the operator can iterate after a config change.
     *
     * @return array{success:bool,filename:?string,filepath:?string,bytes:int,error:?string,template_used:?string,api_hash:?string,warnings:array<int,string>}
     */
    public function generateForOrder(Bestellung $order, bool $force = false): array
    {
        $orderId = isset($order->kBestellung) ? (int) $order->kBestellung : 0;
        if ($orderId <= 0) {
            return $this->failResult('Order is missing a primary key (kBestellung).');
        }

        $configValues  = $this->config->getAll();
        $currentFormat = strtolower((string) ($configValues['format'] ?? 'pdf'));
        $currentTpl    = $this->templateResolver->resolve($order, $configValues);

        // Idempotency check — skip only when:
        //  • caller did NOT request force-regen, AND
        //  • a previous file is still on disk, AND
        //  • the previous file was generated for the SAME format AND the
        //    SAME templateId that the plugin would send right now. NULL and
        //    '' on either side are equivalent ("no template").
        $existing = $this->loadMeta($orderId);
        if (
            !$force
            && null !== $existing
            && !empty($existing['filepath'])
            && is_file((string) $existing['filepath'])
            && strtolower((string) ($existing['format'] ?? '')) === $currentFormat
            && (string) ($existing['template_id_used'] ?? '') === (string) ($currentTpl ?? '')
        ) {
            return [
                'success'       => true,
                'filename'      => isset($existing['filename']) ? (string) $existing['filename'] : null,
                'filepath'      => (string) $existing['filepath'],
                'bytes'         => (int) @filesize((string) $existing['filepath']),
                'error'         => null,
                'template_used' => null === $currentTpl || '' === $currentTpl ? null : (string) $currentTpl,
                'api_hash'      => isset($existing['api_hash']) && '' !== $existing['api_hash']
                    ? (string) $existing['api_hash']
                    : null,
                'warnings'      => [],
            ];
        }

        // Force-regen path: wipe disk artefact + meta row so the rest of the
        // method is a clean-slate generation. Errors during cleanup are
        // tolerated — they should not block a fresh run.
        if ($force) {
            try {
                $this->storage->delete($orderId);
            } catch (\Throwable) {
                // best-effort cleanup
            }
            $this->wipeMeta($orderId);
        }

        // Map the order to invoice JSON. Returns null on empty positions —
        // we surface this as success=false but with a clear "skipped" marker
        // in the error message, NOT a stack trace.
        try {
            $payload = $this->mapper->mapToInvoice($order, $configValues);
        } catch (\Throwable $e) {
            $msg = 'OrderMapper failed: ' . $e->getMessage();
            $this->persistError($orderId, $msg);

            return $this->failResult($msg);
        }

        if (null === $payload) {
            $msg = 'skipped: order has no positions';
            // Skip is not an error per se; we still record it so the admin
            // can see why nothing was produced.
            $this->persistError($orderId, $msg);

            return $this->failResult($msg);
        }

        $country  = (string) ($configValues['country'] ?? 'DE');
        $format   = $currentFormat;
        $template = $currentTpl;

        try {
            $response = $this->client->generate($country, $format, $payload, $template);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            $this->persistError($orderId, $msg);

            return $this->failResult($msg);
        }

        if (empty($response['data']) || !is_string($response['data'])) {
            $msg = $this->client->buildErrorMessage($response) ?? 'Invoice API returned no data.';
            $this->persistError($orderId, $msg);

            return $this->failResult($msg);
        }

        $bytes = base64_decode($response['data'], true);
        if (false === $bytes) {
            $msg = 'API returned non-base64 data.';
            $this->persistError($orderId, $msg);

            return $this->failResult($msg);
        }

        $filename = $this->resolveFilename($response, $payload, $orderId, $format);
        $apiHash  = isset($response['hash']) && is_string($response['hash']) ? $response['hash'] : null;
        $warnings = [];
        if (isset($response['warnings']) && is_array($response['warnings'])) {
            foreach ($response['warnings'] as $w) {
                if (is_string($w)) {
                    $warnings[] = $w;
                } elseif (is_array($w) && isset($w['message']) && is_string($w['message'])) {
                    $warnings[] = $w['message'];
                }
            }
        }

        try {
            $absolutePath = $this->storage->write($orderId, $filename, $bytes);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            $this->persistError($orderId, $msg);

            return $this->failResult($msg);
        }

        $this->persistSuccess($orderId, $filename, $absolutePath, $format, $template, $apiHash);

        return [
            'success'       => true,
            'filename'      => $filename,
            'filepath'      => $absolutePath,
            'bytes'         => strlen($bytes),
            'error'         => null,
            'template_used' => null === $template || '' === $template ? null : (string) $template,
            'api_hash'      => $apiHash,
            'warnings'      => $warnings,
        ];
    }

    /**
     * Pull the filename out of the API response or build a deterministic
     * fallback so a stored file always has a sensible name.
     *
     * @param array<string,mixed> $response
     * @param array<string,mixed> $payload
     */
    private function resolveFilename(array $response, array $payload, int $orderId, string $format): string
    {
        if (isset($response['filename']) && is_string($response['filename']) && '' !== $response['filename']) {
            return $response['filename'];
        }

        $invoiceNumber = '';
        if (isset($payload['invoiceNumber']) && is_string($payload['invoiceNumber'])) {
            $invoiceNumber = $payload['invoiceNumber'];
        }
        $base = '' !== $invoiceNumber ? $invoiceNumber : ('invoice-' . $orderId);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?? $base;
        $base = trim($base, '-_.');
        if ('' === $base) {
            $base = 'invoice-' . $orderId;
        }

        $format = strtolower($format);

        return match ($format) {
            'xrechnung' => $base . '_xrechnung.xml',
            'zugferd'   => $base . '_zugferd.pdf',
            'pdf'       => $base . '.pdf',
            default     => $base . '.' . preg_replace('/[^a-z0-9]/', '', $format),
        };
    }

    /**
     * Build a uniform "failure" result tuple.
     *
     * @return array{success:bool,filename:?string,filepath:?string,bytes:int,error:?string,template_used:?string,api_hash:?string,warnings:array<int,string>}
     */
    private function failResult(string $message): array
    {
        return [
            'success'       => false,
            'filename'      => null,
            'filepath'      => null,
            'bytes'         => 0,
            'error'         => $message,
            'template_used' => null,
            'api_hash'      => null,
            'warnings'      => [],
        ];
    }

    /**
     * Read the meta row for an order. Returns null if the table does not
     * exist or the row is missing. Includes the diagnostic columns used by
     * the idempotency check (`template_id_used`) and by the operator-facing
     * History table (`api_hash`).
     *
     * @return array<string,mixed>|null
     */
    private function loadMeta(int $orderId): ?array
    {
        try {
            $stmt = $this->db->getPDO()->prepare(
                'SELECT `filename`, `filepath`, `format`, `last_error`, `template_id_override`, '
                . '`template_id_used`, `api_hash`, `generated_at` '
                . 'FROM `' . self::META_TABLE . '` WHERE `order_id` = :order_id LIMIT 1',
            );
            $stmt->execute(['order_id' => $orderId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                return null;
            }

            return $row;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Upsert the meta row with a successful generation result. Best-effort —
     * silently no-ops when the meta table does not yet exist. Persists the
     * `template_id_used` (templateId actually sent to the API for this run)
     * so the next idempotency check can compare it against the currently
     * configured templateId, and `api_hash` for operator-side run-to-run
     * content comparison in the History table.
     */
    private function persistSuccess(
        int $orderId,
        string $filename,
        string $absolutePath,
        string $format,
        ?string $templateUsed,
        ?string $apiHash,
    ): void {
        try {
            $stmt = $this->db->getPDO()->prepare(
                'INSERT INTO `' . self::META_TABLE . '` '
                . '(`order_id`, `filename`, `filepath`, `format`, `bytes`, `last_error`, '
                . '`template_id_used`, `api_hash`, `generated_at`) '
                . 'VALUES (:order_id, :filename, :filepath, :format, :bytes, NULL, '
                . ':template_id_used, :api_hash, :generated_at) '
                . 'ON DUPLICATE KEY UPDATE '
                . '`filename` = VALUES(`filename`), '
                . '`filepath` = VALUES(`filepath`), '
                . '`format` = VALUES(`format`), '
                . '`bytes` = VALUES(`bytes`), '
                . '`last_error` = NULL, '
                . '`template_id_used` = VALUES(`template_id_used`), '
                . '`api_hash` = VALUES(`api_hash`), '
                . '`generated_at` = VALUES(`generated_at`)',
            );
            $stmt->execute([
                'order_id'         => $orderId,
                'filename'         => $filename,
                'filepath'         => $absolutePath,
                'format'           => $format,
                'bytes'            => is_file($absolutePath) ? (int) filesize($absolutePath) : 0,
                'template_id_used' => null === $templateUsed || '' === $templateUsed ? null : $templateUsed,
                'api_hash'         => null === $apiHash || '' === $apiHash ? null : $apiHash,
                'generated_at'     => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Meta table or new diagnostic columns may not exist on installs
            // that pre-date this build. The orchestrator still produces a
            // working invoice file; persistence (and therefore the idempotency
            // cache) just does not engage in that case.
        }
    }

    /**
     * Delete the meta row for an order. Used by the force-regen path so a
     * stale `last_error` from a previous failure does not bleed into the
     * fresh attempt.
     */
    private function wipeMeta(int $orderId): void
    {
        try {
            $stmt = $this->db->getPDO()->prepare(
                'DELETE FROM `' . self::META_TABLE . '` WHERE `order_id` = :order_id',
            );
            $stmt->execute(['order_id' => $orderId]);
        } catch (\Throwable) {
            // best-effort; force-regen still produces a fresh file even if
            // the cleanup row hangs around (persistSuccess will overwrite it).
        }
    }

    /**
     * Upsert the meta row with a failure message. Best-effort — must never
     * mask the original error.
     */
    private function persistError(int $orderId, string $errorMessage): void
    {
        try {
            $stmt = $this->db->getPDO()->prepare(
                'INSERT INTO `' . self::META_TABLE . '` '
                . '(`order_id`, `last_error`) '
                . 'VALUES (:order_id, :last_error) '
                . 'ON DUPLICATE KEY UPDATE `last_error` = VALUES(`last_error`)',
            );
            $stmt->execute([
                'order_id'   => $orderId,
                'last_error' => $errorMessage,
            ]);
        } catch (\Throwable) {
            // Last-error persistence must never throw — we are already in an
            // error path. The orchestrator still returns the error to the caller.
        }
    }
}
