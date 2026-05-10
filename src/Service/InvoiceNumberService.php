<?php

declare(strict_types=1);

namespace Plugin\xhubio_invoice_api_xhub\Service;

use JTL\DB\DbInterface;
use RuntimeException;

/**
 * Atomic gap-free invoice-number allocator + token-based format expansion.
 *
 * Backed by the table created in `Bootstrap::installed()`:
 *   xplugin_xhubio_invoice_api_xhub_seq (period VARCHAR PK,
 *                                         current BIGINT UNSIGNED,
 *                                         updated_at DATETIME)
 *
 * The increment uses the LAST_INSERT_ID(expr) trick (same pattern as the
 * Shopware/WooCommerce reference implementations):
 *
 *   INSERT INTO `xplugin_xhubio_invoice_api_xhub_seq` (period, current)
 *   VALUES (:period, LAST_INSERT_ID(1))
 *   ON DUPLICATE KEY UPDATE current = LAST_INSERT_ID(current + 1)
 *
 * Passing the expression in BOTH the VALUES clause and the UPDATE branch
 * makes `PDO::lastInsertId()` return the freshly-allocated value in either
 * branch — first insert OR duplicate update — even though `period` is a
 * VARCHAR primary key (not AUTO_INCREMENT). The whole statement is atomic
 * at the DB level, so concurrent generations cannot share a number.
 *
 * §14 UStG note: every increment is committed BEFORE the API call. If the
 * generate fails, the number is "burned" and the next invoice gets the next
 * value — gaps are tolerable per BFH-Rechtsprechung; duplicates are not.
 */
final class InvoiceNumberService
{
    private const SEQUENCE_TABLE = 'xplugin_xhubio_invoice_api_xhub_seq';

    public function __construct(
        private readonly DbInterface $db,
    ) {
    }

    /**
     * Atomically allocate the next sequence value for the given period.
     *
     * @param string $period Pre-computed period key (e.g. "2026", "2026-05",
     *                       "all"). Callers are expected to derive this from
     *                       the `sequenceReset` plugin setting + the order
     *                       date before calling.
     *
     * @throws RuntimeException When the underlying SQL fails.
     */
    public function nextSequence(string $period): int
    {
        $pdo = $this->db->getPDO();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO `' . self::SEQUENCE_TABLE . '` (`period`, `current`) '
                . 'VALUES (:period, LAST_INSERT_ID(1)) '
                . 'ON DUPLICATE KEY UPDATE `current` = LAST_INSERT_ID(`current` + 1)',
            );
            $stmt->execute(['period' => $period]);

            return (int) $pdo->lastInsertId();
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Failed to allocate invoice sequence for period "' . $period . '": ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Expand the configured number-format string into a concrete invoice
     * number. Tokens supported (matches WC/Shopware/Make/Zapier exactly):
     *
     *   {order_number}  — JTL `cBestellNr` (e.g. "20260001")
     *   {order_id}      — JTL `kBestellung` (numeric primary key)
     *   {year}          — 4-digit year of the invoice (Y)
     *   {month}         — zero-padded month (m)
     *   {day}           — zero-padded day (d)
     *   {seq}           — next sequence value, no padding
     *   {seq:NNNN}      — next sequence value, zero-padded to width = strlen("NNNN")
     *
     * The {seq} family advances the counter via nextSequence() AT MOST ONCE
     * per call; if the format string contains no {seq} token the counter is
     * NOT touched. Padding width is derived from the literal digit string
     * length — using strlen instead of (int) avoids the trap where '0000'
     * casts to 0 and yields no padding.
     *
     * @param array<string,mixed> $context Must supply `order_number`,
     *                                     `order_id`, `year`, `month`,
     *                                     `day`, `period`. The OrderMapper
     *                                     fills these in from the JTL
     *                                     Bestellung; tests can pass them
     *                                     directly.
     */
    public function expandTokens(string $format, array $context): string
    {
        $format = '' !== trim($format) ? $format : 'INV-{order_number}';

        if (preg_match('/\{seq(?::([0-9]+))?\}/', $format, $matches) === 1) {
            $padding = isset($matches[1]) ? strlen($matches[1]) : 0;
            $period  = (string) ($context['period'] ?? ($context['year'] ?? 'all'));
            $seq     = $this->nextSequence($period);
            $seqStr  = $padding > 0
                ? str_pad((string) $seq, $padding, '0', STR_PAD_LEFT)
                : (string) $seq;
            $format = preg_replace('/\{seq(?::[0-9]+)?\}/', $seqStr, $format) ?? $format;
        }

        return strtr($format, [
            '{order_number}' => (string) ($context['order_number'] ?? ''),
            '{order_id}'     => (string) ($context['order_id'] ?? ''),
            '{year}'         => (string) ($context['year'] ?? ''),
            '{month}'        => (string) ($context['month'] ?? ''),
            '{day}'          => (string) ($context['day'] ?? ''),
        ]);
    }

    /**
     * Resolve the period key from the `sequenceReset` plugin setting + the
     * order date components. Exposed publicly so OrderMapper can pass the
     * resolved period into expandTokens() without re-implementing the rule.
     */
    public function resolvePeriod(string $resetMode, string $year, string $month): string
    {
        return match ($resetMode) {
            'never'   => 'all',
            'monthly' => $year . '-' . $month,
            default   => $year, // 'yearly' is the default and recommended
        };
    }
}
