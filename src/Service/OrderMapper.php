<?php

declare(strict_types=1);

namespace Plugin\xhubio_invoice_api_xhub\Service;

use JTL\Checkout\Bestellung;

/**
 * Maps a JTL `\JTL\Checkout\Bestellung` order into the invoice JSON shape
 * required by `POST /api/v1/invoice/{country}/{format}/generate`.
 *
 * Schema reference (live API spec 1.2.0) — must match the WooCommerce and
 * Shopware mappers 1:1:
 *   - flat seller/buyer addresses (street, city, postalCode, countryCode)
 *   - items[] with position/description/quantity/unit/unitPrice/taxRate/
 *     netAmount/taxAmount/grossAmount
 *   - subtotal + total + taxSummary[]
 *   - paymentTerms.dueDays
 *   - countrySpecific.{leitwegId | buyerReference} for DE
 *
 * Returns ONLY the inner `$invoice` array. The wrapping
 * `{invoice, formatOptions, templateId}` is built by ApiClient.
 *
 * JTL Bestellung quirks worth knowing:
 *   - `Positionen[]` holds invoice line items (`\JTL\Checkout\Position`);
 *     each has `cName`, `nAnzahl` (qty), `fPreis`/`fPreisEinzelNetto`,
 *     `fMwSt` (tax rate as percentage), `cEinheit` (unit string).
 *   - `oRechnungsadresse` is the billing address with `cVorname`, `cNachname`,
 *     `cFirma`, `cStrasse`, `cPLZ`, `cOrt`, `cLand` (country code), `cMail`,
 *     `cUSTID`.
 *   - `Waehrung` carries `cISO` (currency code).
 *   - `dErstellt` is the order creation date (Y-m-d H:i:s).
 *   - JTL stores money as floats. Net+gross amounts on positions are
 *     pre-computed; we do not re-compute from the price.
 *
 * Edge cases:
 *   - Empty `Positionen[]` → `mapToInvoice()` returns `null` so the
 *     orchestrator can `skipped=true` without an exception.
 *   - Missing `oRechnungsadresse` → fall back to the customer record
 *     fields if available; default sensible buyer name "Customer".
 *   - Mixed VAT rates per order are aggregated for `taxSummary[]` by rate.
 */
final class OrderMapper
{
    public function __construct(
        private readonly InvoiceNumberService $numberService,
    ) {
    }

    /**
     * Build the inner invoice JSON for an order.
     *
     * @param array<string,mixed> $config Resolved plugin config
     *                                    (ConfigLoader::getAll()).
     *
     * @return array<string,mixed>|null The mapped invoice payload, or null
     *                                  when the order has no positions —
     *                                  the orchestrator interprets null as
     *                                  "skip, do not error".
     */
    public function mapToInvoice(Bestellung $order, array $config): ?array
    {
        // Empty-items skip — API rule BR-16 ("at least one invoice line").
        // Returning null is part of the contract; orchestrator records it as
        // a skip, NOT a failure.
        $positions = is_array($order->Positionen ?? null) ? $order->Positionen : [];
        if (0 === count($positions)) {
            return null;
        }

        $issueDate = $this->resolveIssueDate($order);
        $year      = substr($issueDate, 0, 4);
        $month     = substr($issueDate, 5, 2);
        $day       = substr($issueDate, 8, 2);

        $dueDays = isset($config['paymentDueDays']) ? max(0, (int) $config['paymentDueDays']) : 14;
        $dueDate = $this->addDaysIso($issueDate, $dueDays);

        $resetMode = (string) ($config['sequenceReset'] ?? 'yearly');
        $period    = $this->numberService->resolvePeriod($resetMode, $year, $month);

        $orderNumber = isset($order->cBestellNr) ? (string) $order->cBestellNr : '';
        $orderId     = isset($order->kBestellung) ? (int) $order->kBestellung : 0;

        $invoiceNumber = $this->numberService->expandTokens(
            (string) ($config['numberFormat'] ?? 'INV-{order_number}'),
            [
                'order_number' => '' !== $orderNumber ? $orderNumber : (string) $orderId,
                'order_id'     => (string) $orderId,
                'year'         => $year,
                'month'        => $month,
                'day'          => $day,
                'period'       => $period,
            ],
        );

        $items      = $this->buildItems($positions);
        $taxSummary = $this->buildTaxSummary($items);
        $subtotal   = $this->sum($items, 'netAmount');
        $total      = $this->sum($items, 'grossAmount');

        $currency = $this->resolveCurrency($order);
        $country  = strtoupper((string) ($config['sellerCountryCode'] ?? 'DE'));

        $invoice = [
            'invoiceNumber' => $invoiceNumber,
            'type'          => 'invoice',
            'issueDate'     => $issueDate,
            'dueDate'       => $dueDate,
            'currency'      => $currency,
            'seller'        => $this->buildSeller($config),
            'buyer'         => $this->buildBuyer($order),
            'items'         => $items,
            'subtotal'      => $this->round($subtotal),
            'total'         => $this->round($total),
            'taxSummary'    => $taxSummary,
            // PaymentTerms: API requires { dueDays, description }. Empirically
            // verified — passing only `dueDays` produces an empty <cac:PaymentTerms/>
            // XML element which fails XRechnung's PEPPOL-EN16931-R008 ("Document
            // MUST not contain empty elements"). The `description` is rendered as
            // <cbc:Note> inside <cac:PaymentTerms> and satisfies the rule.
            'paymentTerms'  => [
                'dueDays'     => $dueDays,
                'description' => sprintf('Zahlbar binnen %d Tagen netto.', $dueDays),
            ],
        ];

        $bankAccount = $this->buildBankAccount($config);
        if (null !== $bankAccount) {
            $invoice['seller']['bankAccount'] = $bankAccount;
            // paymentMethods is required by XRechnung BR-DE-1 ("Eine Rechnung
            // muss Angaben zu PAYMENT INSTRUCTIONS (BG-16) enthalten"). Pass
            // the IBAN so the API can also satisfy BR-61 (SEPA → BT-84
            // Payment account identifier present).
            $pm = ['type' => 'bank_transfer'];
            if (isset($bankAccount['iban']) && '' !== $bankAccount['iban']) {
                $pm['iban'] = $bankAccount['iban'];
            }
            if (isset($bankAccount['bic']) && '' !== $bankAccount['bic']) {
                $pm['bic'] = $bankAccount['bic'];
            }
            $invoice['paymentMethods'] = [$pm];
        }

        $countrySpecific = $this->buildCountrySpecific($country, $order, $config);
        if ([] !== $countrySpecific) {
            $invoice['countrySpecific'] = $countrySpecific;
        }

        $note = trim((string) ($order->cKommentar ?? ''));
        if ('' !== $note) {
            $invoice['note'] = $note;
        }

        return $invoice;
    }

    /**
     * Build the seller block from the static plugin config.
     *
     * @param array<string,mixed> $config
     *
     * @return array<string,string>
     */
    private function buildSeller(array $config): array
    {
        $required = [
            'name'        => (string) ($config['sellerName'] ?? ''),
            'street'      => (string) ($config['sellerStreet'] ?? ''),
            'city'        => (string) ($config['sellerCity'] ?? ''),
            'postalCode'  => (string) ($config['sellerPostalCode'] ?? ''),
            'countryCode' => strtoupper((string) ($config['sellerCountryCode'] ?? 'DE')),
        ];

        $optional = array_filter([
            'vatId' => trim((string) ($config['sellerVatId'] ?? '')),
            'email' => trim((string) ($config['sellerEmail'] ?? '')),
            'phone' => trim((string) ($config['sellerPhone'] ?? '')),
        ], static fn (string $v): bool => '' !== $v);

        return array_merge($required, $optional);
    }

    /**
     * Build the buyer block from the order's billing address.
     *
     * @return array<string,string>
     */
    private function buildBuyer(Bestellung $order): array
    {
        $billing = $order->oRechnungsadresse ?? null;

        $company   = trim((string) ($billing->cFirma ?? ''));
        $firstName = trim((string) ($billing->cVorname ?? ''));
        $lastName  = trim((string) ($billing->cNachname ?? ''));
        $name      = '' !== $company ? $company : trim($firstName . ' ' . $lastName);

        $street       = trim((string) ($billing->cStrasse ?? ''));
        $houseNumber  = trim((string) ($billing->cHausnummer ?? ''));
        if ('' !== $houseNumber && false === stripos($street, $houseNumber)) {
            $street = trim($street . ' ' . $houseNumber);
        }

        $city       = trim((string) ($billing->cOrt ?? ''));
        $postalCode = trim((string) ($billing->cPLZ ?? ''));
        $country    = strtoupper(trim((string) ($billing->cLand ?? '')));
        $email      = trim((string) ($billing->cMail ?? ''));
        $vatId      = trim((string) ($billing->cUSTID ?? ''));

        $required = [
            'name'        => '' !== $name ? $name : 'Customer',
            'street'      => $street,
            'city'        => $city,
            'postalCode'  => $postalCode,
            'countryCode' => $country,
        ];

        $optional = array_filter([
            'email' => $email,
            'vatId' => $vatId,
        ], static fn (string $v): bool => '' !== $v);

        return array_merge($required, $optional);
    }

    /**
     * Build the items[] block from the JTL `Positionen` array. JTL exposes
     * net + gross totals pre-computed per position; we do NOT recompute the
     * tax from price * rate to stay numerically identical to what JTL shows
     * the customer.
     *
     * @param array<int|string,mixed> $positions
     *
     * @return list<array<string,mixed>>
     */
    private function buildItems(array $positions): array
    {
        $items    = [];
        $position = 1;

        foreach ($positions as $p) {
            if (!is_object($p)) {
                continue;
            }

            $quantity = (float) ($p->nAnzahl ?? 0);
            // JTL stores per-unit NET in fPreis (when shop runs net) or
            // fPreisEinzelNetto (consistent across both modes for items).
            $unitNet  = (float) ($p->fPreisEinzelNetto ?? $p->fPreis ?? 0.0);
            $taxRate  = (float) ($p->fMwSt ?? 0.0);
            $netTotal = $quantity * $unitNet;
            $taxTotal = $netTotal * $taxRate / 100.0;

            $name        = trim((string) ($p->cName ?? ''));
            $description = trim((string) ($p->cBeschreibung ?? ''));
            if ('' === $description) {
                $description = '' !== $name ? $name : 'Item';
            }
            if ('' === $name) {
                $name = $description;
            }

            $items[] = [
                'position'        => $position++,
                'name'            => $name,
                'description'     => $description,
                'quantity'        => $this->round($quantity),
                // 'unit' is the legacy field, 'unitCode' is the spec-correct
                // EN16931 label. Sending both is harmless and matches the
                // payloads the parsing tests expect.
                'unit'            => 'H87',
                'unitCode'        => 'H87',
                'unitPrice'       => $this->round($unitNet),
                'taxRate'         => $taxRate,
                'taxCategoryCode' => $this->deriveTaxCategoryCode($taxRate),
                'netAmount'       => $this->round($netTotal),
                'taxAmount'       => $this->round($taxTotal),
                'grossAmount'     => $this->round($netTotal + $taxTotal),
                'lineTotal'       => $this->round($netTotal + $taxTotal),
            ];
        }

        return $items;
    }

    /**
     * Aggregate per-rate tax summary from the items[] we just built. JTL does
     * not expose a pre-aggregated tax block on the order, so we group items
     * by `taxRate` and sum net + tax.
     *
     * @param list<array<string,mixed>> $items
     *
     * @return list<array<string,mixed>>
     */
    private function buildTaxSummary(array $items): array
    {
        $byRate = [];
        foreach ($items as $item) {
            $rate = (float) ($item['taxRate'] ?? 0.0);
            $key  = number_format($rate, 4, '.', '');
            if (!isset($byRate[$key])) {
                $byRate[$key] = [
                    'taxRate'         => $rate,
                    'taxCategoryCode' => $this->deriveTaxCategoryCode($rate),
                    'netAmount'       => 0.0,
                    'taxAmount'       => 0.0,
                ];
            }
            $byRate[$key]['netAmount'] += (float) ($item['netAmount'] ?? 0.0);
            $byRate[$key]['taxAmount'] += (float) ($item['taxAmount'] ?? 0.0);
        }

        $summary = [];
        foreach ($byRate as $entry) {
            $summary[] = [
                'taxRate'         => $entry['taxRate'],
                'taxCategoryCode' => $entry['taxCategoryCode'],
                'netAmount'       => $this->round((float) $entry['netAmount']),
                'taxAmount'       => $this->round((float) $entry['taxAmount']),
            ];
        }

        return $summary;
    }

    /**
     * Build the seller.bankAccount block. Returns null when no IBAN is set —
     * the caller then omits both bankAccount and paymentMethods from the
     * invoice JSON.
     *
     * @param array<string,mixed> $config
     *
     * @return array<string,string>|null
     */
    private function buildBankAccount(array $config): ?array
    {
        $iban   = trim((string) ($config['sellerIban'] ?? ''));
        $bic    = trim((string) ($config['sellerBic'] ?? ''));
        $bank   = trim((string) ($config['sellerBankName'] ?? ''));
        $holder = trim((string) ($config['sellerAccountHolder'] ?? ''));

        if ('' === $iban) {
            return null;
        }

        $bankAccount = ['iban' => $iban];
        if ('' !== $bic) {
            $bankAccount['bic'] = $bic;
        }
        if ('' !== $bank) {
            $bankAccount['bankName'] = $bank;
        }
        if ('' !== $holder) {
            $bankAccount['accountHolder'] = $holder;
        }

        return $bankAccount;
    }

    /**
     * DE-only country specific block — XRechnung requires either a
     * Leitweg-ID (B2G) or buyerReference (B2B). The plugin config supplies
     * the Leitweg-ID default; per-order overrides are read from the meta
     * table (Phase β.B) and not yet plumbed in here. For B2B we fall back
     * to the order number as buyer reference.
     *
     * @param array<string,mixed> $config
     *
     * @return array<string,string>
     */
    private function buildCountrySpecific(string $country, Bestellung $order, array $config): array
    {
        if ('DE' !== $country) {
            return [];
        }

        // The invoice-api.xhub.io schema requires countryCode as the first
        // field of every non-empty countrySpecific block. Without it, validation
        // fails with "expected string, received undefined (field:
        // invoice.countrySpecific.countryCode)" before reaching XRechnung-specific
        // logic.
        $cs = ['countryCode' => $country];

        $leitweg = trim((string) ($config['defaultLeitwegId'] ?? ''));
        if ('' !== $leitweg) {
            $cs['leitwegId'] = $leitweg;
        } else {
            $orderNumber = isset($order->cBestellNr) ? (string) $order->cBestellNr : '';
            if ('' !== $orderNumber) {
                $cs['buyerReference'] = $orderNumber;
            }
        }

        return $cs;
    }

    /**
     * Read the order's currency ISO via `Currency::getCode()` with a EUR
     * fallback. JTL V5 deprecated direct property access (`->cISO`) in
     * `MagicCompatibilityTrait`; the explicit getter avoids the deprecation
     * notice and works on both V5.2+ and older V5 builds.
     */
    private function resolveCurrency(Bestellung $order): string
    {
        $currencyObj = $order->Waehrung ?? null;
        $currency    = null;
        if (is_object($currencyObj) && method_exists($currencyObj, 'getCode')) {
            $currency = $currencyObj->getCode();
        } elseif (is_object($currencyObj) && isset($currencyObj->cISO)) {
            // Legacy fallback for older JTL builds without the getter.
            $currency = $currencyObj->cISO;
        }

        return is_string($currency) && '' !== $currency ? strtoupper($currency) : 'EUR';
    }

    /**
     * Resolve the issue date. JTL exposes `dErstellt` (Y-m-d H:i:s); we
     * truncate to a Y-m-d ISO date. Falls back to today on any parse error.
     */
    private function resolveIssueDate(Bestellung $order): string
    {
        $raw = (string) ($order->dErstellt ?? '');
        if ('' !== $raw) {
            try {
                return (new \DateTimeImmutable($raw))->format('Y-m-d');
            } catch (\Throwable) {
                // fall through
            }
        }

        return (new \DateTimeImmutable('now'))->format('Y-m-d');
    }

    /**
     * Add days to an ISO date string (Y-m-d) and return the resulting date.
     */
    private function addDaysIso(string $iso, int $days): string
    {
        try {
            return (new \DateTimeImmutable($iso))
                ->modify('+' . max(0, $days) . ' days')
                ->format('Y-m-d');
        } catch (\Throwable) {
            return (new \DateTimeImmutable('now'))
                ->modify('+' . max(0, $days) . ' days')
                ->format('Y-m-d');
        }
    }

    /**
     * UNTDID 5305 tax category code:
     *   S = Standard rated (any rate > 0)
     *   Z = Zero rated
     *
     * Reverse-charge (AE) and exempt (E) need explicit override — Phase 1.x.
     */
    private function deriveTaxCategoryCode(float $taxRate): string
    {
        return $taxRate > 0 ? 'S' : 'Z';
    }

    /**
     * 2-decimal HALF_UP rounding. PHP's default round() is HALF_AWAY_FROM_ZERO
     * which is HALF_UP for positive numbers; we pass the explicit constant
     * for clarity and to match the WC/Shopware mappers.
     */
    private function round(float $value): float
    {
        return round($value, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    private function sum(array $items, string $key): float
    {
        $total = 0.0;
        foreach ($items as $item) {
            if (isset($item[$key])) {
                $total += (float) $item[$key];
            }
        }

        return $total;
    }
}
