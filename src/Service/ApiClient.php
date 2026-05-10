<?php

declare(strict_types=1);

namespace Plugin\xhubio_invoice_api_xhub\Service;

use RuntimeException;

/**
 * HTTP wrapper for invoice-api.xhub.io built on the PHP curl extension.
 *
 * JTL-Shop is a plain-PHP shop (no Symfony HttpClient available in the plugin
 * sandbox) so we drop down to libcurl directly. Behaviour mirrors the
 * Shopware/WooCommerce reference implementations 1:1:
 *   - Bearer auth header on every request
 *   - 30 s default timeout
 *   - Body sent as application/json; response decoded as JSON
 *   - On non-2xx OR success=false the API error is normalised into a
 *     human-readable string and raised as \RuntimeException
 *
 * The service is stateless w.r.t. credentials in the constructor; pass apiKey
 * + baseUrl in once and reuse the instance for the order. The unused parts of
 * the OpenAPI surface (parse-auto-detect, get-formats) are intentionally
 * omitted — they are not needed by the generation flow.
 */
final class ApiClient
{
    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly int $timeout;

    public function __construct(string $apiKey, string $baseUrl, int $timeout = 30)
    {
        $this->apiKey  = trim($apiKey);
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->timeout = $timeout > 0 ? $timeout : 30;
    }

    /**
     * Generate an e-invoice document via
     * `POST /api/v1/invoice/{country}/{format}/generate`.
     *
     * On success the API returns base64-encoded bytes in the `data` field
     * along with `mimeType`, `filename`, `format`, `country`. The caller is
     * responsible for base64-decoding `data` and writing it to disk.
     *
     * @param array<string,mixed> $payload Mapped invoice JSON (without wrapper)
     *
     * @return array<string,mixed> Decoded JSON response
     *
     * @throws RuntimeException On any HTTP, transport or API logical error.
     */
    public function generate(string $country, string $format, array $payload, ?string $templateId = null): array
    {
        $body = [
            'invoice'       => $payload,
            'formatOptions' => new \stdClass(),
        ];

        $templateId = null !== $templateId ? trim($templateId) : '';
        if ('' !== $templateId) {
            $body['templateId'] = $templateId;
        }

        $path = sprintf(
            '/api/v1/invoice/%s/%s/generate',
            strtolower($country),
            strtolower($format),
        );

        return $this->request('POST', $path, $body);
    }

    /**
     * Validate an invoice payload via `POST /api/v1/invoice/{country}/validate`.
     * Used by admin UIs to pre-flight-check a payload before generation.
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     *
     * @throws RuntimeException
     */
    public function validate(string $country, array $payload): array
    {
        $path = sprintf('/api/v1/invoice/%s/validate', strtolower($country));

        return $this->request('POST', $path, ['invoice' => $payload]);
    }

    /**
     * Parse a base64-encoded e-invoice document back into structured JSON via
     * `POST /api/v1/invoice/{country}/{format}/parse`.
     *
     * @return array<string,mixed>
     *
     * @throws RuntimeException
     */
    public function parse(string $country, string $format, string $documentBase64): array
    {
        $path = sprintf(
            '/api/v1/invoice/%s/%s/parse',
            strtolower($country),
            strtolower($format),
        );

        return $this->request('POST', $path, ['data' => $documentBase64]);
    }

    /**
     * Build a human-readable error string from a failed API response. Public
     * so callers (e.g. InvoiceGenerator) can surface a useful message even
     * when ApiClient itself decided not to throw (e.g. validate() with valid=false).
     *
     * Handles Zod-style {error: [{path, message}]} arrays, business-rule
     * {errors: [{field, message}]}, complianceErrors[] and bare error/message.
     *
     * @param array<string,mixed> $response
     */
    public function buildErrorMessage(array $response): ?string
    {
        if (!empty($response['error']) && is_array($response['error'])) {
            $first = reset($response['error']);
            if (is_array($first) && !empty($first['message'])) {
                $msg = (string) $first['message'];
                if (!empty($first['path']) && is_array($first['path'])) {
                    $msg .= ' (field: ' . implode('.', array_map('strval', $first['path'])) . ')';
                }

                return $msg;
            }
        }

        if (!empty($response['errors']) && is_array($response['errors'])) {
            $first = reset($response['errors']);
            if (is_array($first) && !empty($first['message'])) {
                $msg = (string) $first['message'];
                if (!empty($first['field'])) {
                    $msg .= ' (field: ' . (string) $first['field'] . ')';
                }

                return $msg;
            }
        }

        if (!empty($response['complianceErrors']) && is_array($response['complianceErrors'])) {
            $first = reset($response['complianceErrors']);
            if (is_array($first) && !empty($first['message'])) {
                return (string) $first['message'];
            }
        }

        if (!empty($response['error']) && is_string($response['error'])) {
            return $response['error'];
        }

        if (!empty($response['message']) && is_string($response['message'])) {
            return $response['message'];
        }

        return null;
    }

    /**
     * Low-level curl wrapper. Always throws \RuntimeException on errors so
     * callers can wrap a single try/catch around the call site.
     *
     * @param array<string,mixed>|null $body
     *
     * @return array<string,mixed>
     *
     * @throws RuntimeException
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        if ('' === $this->apiKey) {
            throw new RuntimeException('API key is not configured. Open Invoice-api.xhub settings to add it.');
        }
        if ('' === $this->baseUrl) {
            throw new RuntimeException('API base URL is not configured.');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP curl extension is required by Invoice-api.xhub plugin but not loaded.');
        }

        $url = $this->baseUrl . $path;

        $ch = curl_init();
        if (false === $ch) {
            throw new RuntimeException('Unable to initialise curl handle.');
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];

        $jsonBody = null;
        if (null !== $body) {
            $headers[] = 'Content-Type: application/json';
            $jsonBody  = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (false === $jsonBody) {
                curl_close($ch);
                throw new RuntimeException('Failed to JSON-encode request body: ' . json_last_error_msg());
            }
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $this->timeout));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if (null !== $jsonBody) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $raw = curl_exec($ch);
        if (false === $raw) {
            $err = curl_error($ch) ?: 'unknown curl error';
            curl_close($ch);
            throw new RuntimeException('Network error talking to invoice-api.xhub: ' . $err);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $parsed = json_decode((string) $raw, true);
        if (!is_array($parsed)) {
            throw new RuntimeException(
                sprintf('Invoice API returned a non-JSON response (HTTP %d).', $status),
            );
        }

        if ($status < 200 || $status >= 300) {
            $message = $this->buildErrorMessage($parsed)
                ?? sprintf('Invoice API HTTP error %d.', $status);

            throw new RuntimeException($message);
        }

        // Some endpoints (validate) return success=true with valid=false +
        // errors[] — caller decides what to do. Other 2xx with success=false
        // surfaces an exception so callers cannot accidentally accept a
        // failed payload.
        if (isset($parsed['success']) && false === $parsed['success']) {
            $message = $this->buildErrorMessage($parsed) ?? 'Invoice API reported failure.';
            throw new RuntimeException($message);
        }

        return $parsed;
    }
}
