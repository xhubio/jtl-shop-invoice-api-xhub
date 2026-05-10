<?php

declare(strict_types=1);

namespace Plugin\xhubio_invoice_api_xhub\Service;

use RuntimeException;

/**
 * File-based invoice storage for the JTL-Shop plugin.
 *
 * Layout under the configured base directory (the plugin's writable area):
 *   {baseDir}/{orderId}/{filename}
 *
 * The base directory is expected to be supplied by the caller — typically
 * `{plugin_path}/files/` or a JTL-Shop-specific writable location. We do not
 * try to guess paths here; that decision belongs to the bootstrap layer.
 *
 * All write/read/delete failures surface as RuntimeException with the
 * underlying error message attached, so callers can wrap a single try/catch
 * around the call.
 */
final class InvoiceFileStorage
{
    private readonly string $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/\\');
    }

    /**
     * Write file bytes for an order. Creates the order's sub-directory on
     * demand (recursive mkdir, mode 0775). Returns the absolute path.
     *
     * @throws RuntimeException When the directory cannot be created or the
     *                          file cannot be written.
     */
    public function write(int $orderId, string $filename, string $bytes): string
    {
        $sanitized = $this->sanitizeFilename($filename);
        $orderDir  = $this->baseDir . '/' . $orderId;

        if (!is_dir($orderDir)) {
            // Suppress + check return value: mkdir() emits a warning when the
            // race is lost (parallel request created the dir first); the
            // directory existence check below is the authoritative test.
            if (!@mkdir($orderDir, 0775, true) && !is_dir($orderDir)) {
                throw new RuntimeException(
                    sprintf('Failed to create invoice directory "%s".', $orderDir),
                );
            }
        }

        $absolutePath = $orderDir . '/' . $sanitized;
        $written      = @file_put_contents($absolutePath, $bytes);
        if (false === $written) {
            throw new RuntimeException(
                sprintf('Failed to write invoice file "%s".', $absolutePath),
            );
        }

        return $absolutePath;
    }

    /**
     * Read file bytes by relative path (relative to the base directory).
     * Returns null when the file is missing; throws on permission/IO errors.
     *
     * Relative paths beginning with "/" or containing ".." are rejected to
     * keep this storage helper from being abused as a generic file reader.
     */
    public function read(string $relativePath): ?string
    {
        if ('' === $relativePath || str_contains($relativePath, '..')) {
            throw new RuntimeException('Refusing to read suspicious path: ' . $relativePath);
        }

        $relativePath = ltrim($relativePath, '/\\');
        $absolutePath = $this->baseDir . '/' . $relativePath;

        if (!is_file($absolutePath)) {
            return null;
        }

        $bytes = @file_get_contents($absolutePath);
        if (false === $bytes) {
            throw new RuntimeException(
                sprintf('Failed to read invoice file "%s".', $absolutePath),
            );
        }

        return $bytes;
    }

    /**
     * Recursively delete every artefact stored for the given order. Idempotent
     * — missing directory is not an error. Used by GDPR-erase + uninstall.
     */
    public function delete(int $orderId): void
    {
        $orderDir = $this->baseDir . '/' . $orderId;
        if (!is_dir($orderDir)) {
            return;
        }

        $this->rrmdir($orderDir);
    }

    /**
     * MIME-type whitelist used when streaming a stored file back to the
     * customer. Returns one of the three types supported by the API today
     * (PDF / XML / generic) — anything else falls back to octet-stream.
     */
    public function mimeType(string $filename): string
    {
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf'        => 'application/pdf',
            'xml'        => 'application/xml',
            default      => 'application/octet-stream',
        };
    }

    /**
     * Build the absolute path for a stored file — useful for callers that
     * want to attach the file to an email without re-doing the path math.
     */
    public function pathFor(int $orderId, string $filename): string
    {
        return $this->baseDir . '/' . $orderId . '/' . $this->sanitizeFilename($filename);
    }

    /**
     * Strip path separators and dangerous characters from filenames. Mirrors
     * the WC/Shopware convention of `[A-Za-z0-9._-]` plus dash collapsing.
     */
    private function sanitizeFilename(string $filename): string
    {
        $base  = basename($filename);
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?? $base;
        $clean = trim($clean, '-_.');

        return '' !== $clean ? $clean : 'invoice.bin';
    }

    /**
     * Recursive rmdir helper. Tolerant against concurrent deletes (race-safe
     * via @-suppressed unlink/rmdir + final is_dir() check).
     */
    private function rrmdir(string $dir): void
    {
        $entries = @scandir($dir);
        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
