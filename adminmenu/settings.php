<?php

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

// Cache-bust: prevent the browser from serving a stale snapshot of this
// page after the operator changes anything in the Configuration tab.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/**
 * Admin entry-point for "Invoice-api.xhub for JTL-Shop".
 *
 * This file is loaded by JTL-Shop when the operator opens the
 * AdminMenu → "Invoice-api.xhub Settings" menu item declared in info.xml.
 * It is INFORMATIONAL only — JTL-Shop renders the actual settings form
 * automatically from the <Settings> block of info.xml. We provide a status
 * overview so the operator can see at a glance whether the plugin is wired
 * up correctly.
 *
 * JTL-Shop injects `$oPlugin` (\JTL\Plugin\PluginInterface) into the local
 * scope, plus its own admin layout wrapper. We therefore emit raw semantic
 * HTML / Bootstrap 4 fragments and let the wrapper take care of <html>,
 * <head>, navigation etc.
 */

use JTL\Plugin\PluginInterface;
use JTL\Shop;
use Plugin\xhubio_invoice_api_xhub\Service\ApiClient;
use Plugin\xhubio_invoice_api_xhub\Service\ConfigLoader;
use Plugin\xhubio_invoice_api_xhub\Service\InvoiceFileStorage;
use Plugin\xhubio_invoice_api_xhub\Service\InvoiceGenerator;
use Plugin\xhubio_invoice_api_xhub\Service\InvoiceNumberService;
use Plugin\xhubio_invoice_api_xhub\Service\OrderMapper;
use Plugin\xhubio_invoice_api_xhub\Service\PluginContext;
use Plugin\xhubio_invoice_api_xhub\Service\TemplateResolver;

if (!isset($oPlugin) || !($oPlugin instanceof PluginInterface)) {
    echo '<div class="alert alert-danger">Plugin context not loaded.</div>';

    return;
}

$config       = new ConfigLoader($oPlugin);
$values       = $config->getAll();

// Handle "Generate Invoice" form submit: re-fire our hook for the chosen order
// to test the API end-to-end without going through JTL-Wawi.
$generateResult = null;
if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && isset($_POST['xhubio_generate_for'])) {
    $orderID = (int) $_POST['xhubio_generate_for'];
    if ($orderID > 0) {
        try {
            $db               = Shop::Container()->getDB();
            $apiClient2       = new ApiClient((string) $values['apiKey'], (string) $values['baseUrl']);
            $storage2         = new InvoiceFileStorage((string) PluginContext::filesDir());
            $numberService    = new InvoiceNumberService($db);
            $templateResolver = new TemplateResolver($db);
            $mapper           = new OrderMapper($numberService);
            $generator        = new InvoiceGenerator($apiClient2, $mapper, $templateResolver, $storage2, $config, $db);
            $order            = new \JTL\Checkout\Bestellung($orderID, true);
            // Manual trigger from the admin UI is always force-regen: the
            // operator clicked "Generate now" because they want fresh output
            // (typically after a Configuration-tab change). Idempotency is
            // only meaningful for the auto-Hook-181 dispatcher.
            $generateResult   = $generator->generateForOrder($order, true);
            $generateResult['orderID'] = $orderID;
        } catch (\Throwable $e) {
            $generateResult = [
                'success' => false,
                'error'   => 'Exception: ' . $e->getMessage(),
                'orderID' => $orderID,
            ];
        }
    }
}


$apiKey       = trim((string) ($values['apiKey'] ?? ''));
$baseUrl      = trim((string) ($values['baseUrl'] ?? ''));
$trigger      = (string) ($values['trigger'] ?? 'off');
$country      = (string) ($values['country'] ?? '');
$format       = (string) ($values['format'] ?? '');
$numberFormat = (string) ($values['numberFormat'] ?? '');

// Storage writability check
$filesDir         = PluginContext::filesDir();
$storageWritable  = null !== $filesDir && is_dir($filesDir) && is_writable($filesDir);
$storageExists    = null !== $filesDir && is_dir($filesDir);

// Last generated invoice (best-effort — meta table may not exist yet on
// older installs that pre-date Phase β.B).
$lastGeneratedAt = null;
$generatedCount  = 0;
try {
    $pdo  = Shop::Container()->getDB()->getPDO();
    $stmt = $pdo->query(
        'SELECT MAX(`generated_at`) AS last_generated, COUNT(*) AS total '
        . 'FROM `xplugin_xhubio_invoice_api_xhub_meta` '
        . 'WHERE `generated_at` IS NOT NULL',
    );
    if (false !== $stmt) {
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $lastGeneratedAt = isset($row['last_generated']) && '' !== $row['last_generated']
                ? (string) $row['last_generated']
                : null;
            $generatedCount  = (int) ($row['total'] ?? 0);
        }
    }
} catch (\Throwable) {
    // Meta table may not exist on a fresh install before Bootstrap::installed()
    // has run. Treat as zero generated invoices.
    $lastGeneratedAt = null;
    $generatedCount  = 0;
}

// Full history of generated invoices — shown as a table below the
// "Generate" form so the operator can review/download every artefact.
$invoiceHistory = [];
try {
    $pdo  = Shop::Container()->getDB()->getPDO();
    $stmt = $pdo->query(
        'SELECT m.order_id, m.filename, m.format, m.bytes, m.generated_at, m.last_error, '
        . '       m.template_id_used, m.api_hash, '
        . '       b.cBestellNr '
        . 'FROM `xplugin_xhubio_invoice_api_xhub_meta` m '
        . 'LEFT JOIN `tbestellung` b ON b.kBestellung = m.order_id '
        . 'ORDER BY m.generated_at DESC, m.order_id DESC LIMIT 50'
    );
    if (false !== $stmt) {
        $invoiceHistory = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
} catch (\Throwable) {
    $invoiceHistory = [];
}

$badgeOk     = '<span class="badge badge-success">OK</span>';
$badgeMissing = '<span class="badge badge-danger">Missing</span>';
$badgeWarn   = '<span class="badge badge-warning">Check</span>';

// Recent orders for the Generate-form. JTL admin doesn't expose an order
// edit page so we let the operator pick from a dropdown here. Limit to 20
// most-recent so the dropdown stays usable on large shops.
$orderOptions = [];
try {
    $pdo  = Shop::Container()->getDB()->getPDO();
    $stmt = $pdo->query(
        'SELECT b.kBestellung, b.cBestellNr, b.cStatus, b.fGesamtsumme, b.dErstellt, '
        . '       m.filename AS xhubio_filename, m.last_error AS xhubio_error '
        . 'FROM `tbestellung` b '
        . 'LEFT JOIN `xplugin_xhubio_invoice_api_xhub_meta` m ON m.order_id = b.kBestellung '
        . 'ORDER BY b.kBestellung DESC LIMIT 20'
    );
    if (false !== $stmt) {
        $orderOptions = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
} catch (\Throwable) {
    $orderOptions = [];
}

?>

<?php
// Build the public download URL for a generated invoice. The plugin folder
// lives at /plugins/<id>/files/... under the document root, so Apache serves
// the PDF directly. We compute the URL relative to URL_SHOP so it works on
// any deployment (localhost, staging, prod).
$buildDownloadUrl = static function (?int $orderID, ?string $filename): ?string {
    if (null === $orderID || null === $filename || '' === $filename) {
        return null;
    }
    $base = defined('URL_SHOP') ? rtrim((string) URL_SHOP, '/') : '';

    return $base . '/plugins/' . PluginContext::PLUGIN_ID . '/files/' . $orderID . '/' . rawurlencode($filename);
};
?>

<?php if (null !== $generateResult): ?>
<div class="card mt-3 <?= $generateResult['success'] ? 'border-success' : 'border-danger' ?>">
    <div class="card-header <?= $generateResult['success'] ? 'bg-success text-white' : 'bg-danger text-white' ?>">
        <h5 class="mb-0">
            <?= $generateResult['success'] ? '✓ Invoice generated' : '✗ Generation failed' ?>
            for order #<?= (int) ($generateResult['orderID'] ?? 0) ?>
        </h5>
    </div>
    <div class="card-body">
        <?php if ($generateResult['success']): ?>
            <?php
            $dl       = $buildDownloadUrl((int) ($generateResult['orderID'] ?? 0), (string) ($generateResult['filename'] ?? ''));
            $tplUsed  = $generateResult['template_used'] ?? null;
            $apiHash  = $generateResult['api_hash'] ?? null;
            $apiWarns = is_array($generateResult['warnings'] ?? null) ? $generateResult['warnings'] : [];
            ?>
            <p>
                <strong>Filename:</strong>
                <?php if (null !== $dl): ?>
                    <a href="<?= htmlspecialchars($dl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                        <?= htmlspecialchars((string) ($generateResult['filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php else: ?>
                    <code><?= htmlspecialchars((string) ($generateResult['filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
                <?php endif; ?>
                <br>
                <strong>Bytes:</strong> <?= (int) ($generateResult['bytes'] ?? 0) ?>
                <br>
                <strong>Template sent to API:</strong>
                <?php if (null !== $tplUsed && '' !== $tplUsed): ?>
                    <code><?= htmlspecialchars((string) $tplUsed, ENT_QUOTES, 'UTF-8') ?></code>
                <?php else: ?>
                    <span class="text-muted">(none — API used the system default template)</span>
                <?php endif; ?>
                <?php if (null !== $apiHash && '' !== $apiHash): ?>
                    <br>
                    <strong>API content hash:</strong>
                    <code style="font-size: 0.85em;"><?= htmlspecialchars((string) $apiHash, ENT_QUOTES, 'UTF-8') ?></code>
                    <small class="text-muted ml-2">(compare with previous runs to confirm content changed)</small>
                <?php endif; ?>
            </p>
            <?php if (!empty($apiWarns)): ?>
                <div class="alert alert-warning py-2">
                    <strong>API warnings:</strong>
                    <ul class="mb-0">
                        <?php foreach ($apiWarns as $w): ?>
                            <li><?= htmlspecialchars((string) $w, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if (null !== $tplUsed && '' !== $tplUsed): ?>
                <div class="alert alert-info py-2 small mb-2">
                    The plugin sent your custom template UUID. If the rendered PDF still
                    looks identical to the default, the template content itself has not
                    been customised yet — open it at
                    <a href="https://console.invoice-api.xhub.io/pdf/templates" target="_blank" rel="noopener">console.invoice-api.xhub.io/pdf/templates</a>
                    and edit the layout / logo / colors to make the difference visible.
                </div>
            <?php endif; ?>
            <?php if (null !== $dl): ?>
                <a href="<?= htmlspecialchars($dl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-success">
                    <i class="fal fa-download"></i> Download / view PDF
                </a>
            <?php endif; ?>
        <?php else: ?>
            <p class="mb-0"><strong>Error:</strong> <code><?= htmlspecialchars((string) ($generateResult['error'] ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?></code></p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card mt-3 border-primary">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">Generate invoice (manual trigger)</h5>
    </div>
    <div class="card-body">
        <p class="small text-muted">
            JTL-Shop's admin doesn't expose an order-status-change UI (that's done via
            JTL-Wawi). Use this form to trigger our plugin's invoice generation
            for any order — same code path that fires when Hook 181 dispatches.
        </p>
        <?php if (empty($orderOptions)): ?>
            <div class="alert alert-warning mb-0">No orders found in <code>tbestellung</code>. Run <code>make</code> to seed a test order.</div>
        <?php else: ?>
            <form method="post" class="form-inline">
                <label class="mr-2" for="xhubio_generate_for">Order:</label>
                <select name="xhubio_generate_for" id="xhubio_generate_for" class="form-control mr-2" style="min-width: 320px;">
                    <?php foreach ($orderOptions as $row): ?>
                        <?php
                        $kBestellung = (int) $row['kBestellung'];
                        $bestellNr   = (string) $row['cBestellNr'];
                        $status      = (string) $row['cStatus'];
                        $hasInvoice  = !empty($row['xhubio_filename']);
                        $hasError    = !empty($row['xhubio_error']);
                        $marker      = $hasInvoice ? ' ✓' : ($hasError ? ' ✗' : '');
                        ?>
                        <option value="<?= $kBestellung ?>">
                            #<?= $kBestellung ?> · <?= htmlspecialchars($bestellNr, ENT_QUOTES, 'UTF-8') ?>
                            (status=<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>)<?= $marker ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">
                    <i class="fal fa-bolt"></i> Generate now
                </button>
            </form>
            <p class="small text-muted mt-2 mb-0">
                Marker legend: <strong>✓</strong> = invoice already generated &nbsp;|&nbsp;
                <strong>✗</strong> = previous attempt errored
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">
        <h5 class="mb-0">Generated invoices (latest 50)</h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($invoiceHistory)): ?>
            <div class="alert alert-info m-3 mb-3">
                No invoices generated yet. Use the form above to trigger generation
                for an order.
            </div>
        <?php else: ?>
            <table class="table table-sm table-striped mb-0">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Bestellnr.</th>
                        <th>Filename</th>
                        <th>Format</th>
                        <th>Template</th>
                        <th>API hash</th>
                        <th>Bytes</th>
                        <th>Generated at</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoiceHistory as $h): ?>
                        <?php
                        $hOrderID  = (int) ($h['order_id'] ?? 0);
                        $hFile     = (string) ($h['filename'] ?? '');
                        $hFormat   = (string) ($h['format'] ?? '');
                        $hBytes    = (int) ($h['bytes'] ?? 0);
                        $hGenAt    = (string) ($h['generated_at'] ?? '');
                        $hError    = (string) ($h['last_error'] ?? '');
                        $hBestNr   = (string) ($h['cBestellNr'] ?? '');
                        $hTplUsed  = (string) ($h['template_id_used'] ?? '');
                        $hApiHash  = (string) ($h['api_hash'] ?? '');
                        $hDl       = $hFile !== '' ? $buildDownloadUrl($hOrderID, $hFile) : null;
                        ?>
                        <tr>
                            <td>#<?= $hOrderID ?></td>
                            <td><?= htmlspecialchars($hBestNr, ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if (null !== $hDl): ?>
                                    <a href="<?= htmlspecialchars($hDl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                                        <?= htmlspecialchars($hFile, ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($hFormat, ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ('' !== $hTplUsed): ?>
                                    <code title="<?= htmlspecialchars($hTplUsed, ENT_QUOTES, 'UTF-8') ?>" style="font-size: 0.8em;">
                                        <?= htmlspecialchars(substr($hTplUsed, 0, 8) . '…' . substr($hTplUsed, -4), ENT_QUOTES, 'UTF-8') ?>
                                    </code>
                                <?php else: ?>
                                    <span class="text-muted" title="No templateId sent — invoice-api.xhub.io rendered the system default">(default)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ('' !== $hApiHash): ?>
                                    <code title="<?= htmlspecialchars($hApiHash, ENT_QUOTES, 'UTF-8') ?>" style="font-size: 0.8em;">
                                        <?= htmlspecialchars(substr($hApiHash, 0, 12), ENT_QUOTES, 'UTF-8') ?>…
                                    </code>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $hBytes > 0 ? number_format($hBytes, 0, ',', '.') : '<span class="text-muted">—</span>' ?></td>
                            <td><?= htmlspecialchars($hGenAt, ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ('' !== $hError): ?>
                                    <span class="badge badge-danger" title="<?= htmlspecialchars($hError, ENT_QUOTES, 'UTF-8') ?>">Error</span>
                                <?php elseif ('' !== $hFile): ?>
                                    <span class="badge badge-success">OK</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">
        <h5 class="mb-0">Invoice-api.xhub for JTL-Shop</h5>
    </div>
    <div class="card-body">
        <p class="card-text">
            Generates compliant e-invoices from JTL-Shop orders via the
            <a href="https://invoice-api.xhub.io" target="_blank" rel="noopener">invoice-api.xhub.io</a>
            service. Live formats: <strong>PDF</strong>, <strong>XRechnung</strong>, <strong>ZUGFeRD</strong>.
        </p>
        <p class="card-text small text-muted">
            Configure API credentials, seller details and the trigger state in the
            <em>Configuration</em> tab. The status panel below reflects the live wiring.
        </p>
    </div>
</div>

<?php
// Build the section→keys map mirroring info.xml's <Settings> block. Each
// entry maps a config key to a display label + (optional) display flags.
$sections = [
    'API connection' => [
        'apiKey'  => ['label' => 'API key', 'mask' => true],
        'baseUrl' => ['label' => 'API base URL'],
    ],
    'Document defaults' => [
        'country'        => ['label' => 'Country'],
        'format'         => ['label' => 'Format'],
        'trigger'        => ['label' => 'Trigger'],
        'attachToEmail'  => ['label' => 'Attach invoice to customer email'],
        'paymentDueDays' => ['label' => 'Payment due (days)'],
    ],
    'Invoice numbering' => [
        'numberFormat'  => ['label' => 'Number format'],
        'sequenceReset' => ['label' => 'Sequence reset'],
    ],
    'Seller (your business)' => [
        'sellerName'        => ['label' => 'Company name'],
        'sellerVatId'       => ['label' => 'VAT ID'],
        'sellerStreet'      => ['label' => 'Street'],
        'sellerPostalCode'  => ['label' => 'Postal code'],
        'sellerCity'        => ['label' => 'City'],
        'sellerCountryCode' => ['label' => 'Country code'],
        'sellerEmail'       => ['label' => 'Email'],
        'sellerPhone'       => ['label' => 'Phone'],
    ],
    'Payment / bank details' => [
        'sellerIban'          => ['label' => 'IBAN'],
        'sellerBic'           => ['label' => 'BIC'],
        'sellerBankName'      => ['label' => 'Bank name'],
        'sellerAccountHolder' => ['label' => 'Account holder'],
    ],
    'Country specific (DE)' => [
        'defaultLeitwegId' => ['label' => 'Default Leitweg-ID'],
    ],
    'Template' => [
        'templateId' => ['label' => 'Default Template-ID'],
    ],
];

$renderValue = static function (string $key, $value, array $opts) use ($badgeMissing): string {
    $value = (string) ($value ?? '');
    if ('' === $value) {
        return '<span class="text-muted">(not set)</span>';
    }
    if (!empty($opts['mask']) && strlen($value) > 4) {
        return '<code>****' . htmlspecialchars(substr($value, -4), ENT_QUOTES, 'UTF-8') . '</code>';
    }
    return '<code style="word-break: break-all;">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</code>';
};
?>

<div class="card mt-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Effective configuration</h5>
        <small class="text-muted">live snapshot of <code>tplugineinstellungen</code> · loaded at <?= date('H:i:s') ?></small>
    </div>
    <div class="card-body p-0">
        <?php foreach ($sections as $sectionName => $keys): ?>
            <div class="px-3 py-2 bg-light border-bottom font-weight-bold">
                <?= htmlspecialchars($sectionName, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <table class="table table-sm mb-0">
                <tbody>
                    <?php foreach ($keys as $key => $opts): ?>
                        <tr>
                            <th style="width: 280px; padding-left: 1.5rem;">
                                <?= htmlspecialchars($opts['label'], ENT_QUOTES, 'UTF-8') ?>
                                <span class="text-muted small">(<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>)</span>
                            </th>
                            <td><?= $renderValue($key, $values[$key] ?? null, $opts) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">
        <h5 class="mb-0">Plugin runtime</h5>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <tbody>
                <tr>
                    <th style="width: 280px;">Storage directory</th>
                    <td>
                        <?php if (null === $filesDir): ?>
                            <?= $badgeMissing ?> Plugin path not resolvable.
                        <?php elseif (!$storageExists): ?>
                            <?= $badgeWarn ?> <code><?= htmlspecialchars($filesDir, ENT_QUOTES, 'UTF-8') ?></code>
                            (will be created on first invoice)
                        <?php elseif (!$storageWritable): ?>
                            <?= $badgeMissing ?> <code><?= htmlspecialchars($filesDir, ENT_QUOTES, 'UTF-8') ?></code>
                            (not writable — set 0775 on the directory)
                        <?php else: ?>
                            <?= $badgeOk ?> <code><?= htmlspecialchars($filesDir, ENT_QUOTES, 'UTF-8') ?></code>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Last generated invoice</th>
                    <td>
                        <?php if (null !== $lastGeneratedAt): ?>
                            <?= htmlspecialchars($lastGeneratedAt, ENT_QUOTES, 'UTF-8') ?>
                            <span class="text-muted">(<?= (int) $generatedCount ?> total)</span>
                        <?php else: ?>
                            <span class="text-muted">No invoices generated yet.</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-3 mb-4">
    <div class="card-header">
        <h5 class="mb-0">Quick reference</h5>
    </div>
    <div class="card-body">
        <p class="mb-2">
            <strong>API Console:</strong>
            <a href="https://console.invoice-api.xhub.io/api-keys" target="_blank" rel="noopener">Manage API keys</a>
            ·
            <a href="https://console.invoice-api.xhub.io/pdf/templates" target="_blank" rel="noopener">Custom PDF templates</a>
            ·
            <a href="https://console.invoice-api.xhub.io" target="_blank" rel="noopener">Plans &amp; pricing</a>
        </p>
        <p class="mb-2">
            <strong>How it flows:</strong> Order status change &nbsp;→&nbsp;
            mapped to plugin trigger setting &nbsp;→&nbsp;
            API call to <code>service.invoice-api.xhub.io</code> &nbsp;→&nbsp;
            file stored under <code>plugins/xhubio_invoice_api_xhub/files/&lt;orderID&gt;/</code>
            &nbsp;→&nbsp; row added to the &ldquo;Generated invoices&rdquo; history above.
        </p>
        <p class="mb-2">
            <strong>Compliance:</strong> §14 UStG (atomic, gap-free numbering with the
            <code>{seq:0000}</code> format token) · EN 16931 · XRechnung 3.0 · Peppol BIS Billing 3.0.
            Invoice files live on your own server &mdash; no third-party storage.
        </p>
        <p class="mb-2">
            <strong>Troubleshoot:</strong> Generation failures appear with the full
            API error message in the &ldquo;Generated invoices&rdquo; history above
            (red &ldquo;Error&rdquo; badge, hover for details). For unresolved issues
            email <a href="mailto:support.invoice-api@xhub.io">support.invoice-api@xhub.io</a>
            with the order ID + the error string.
        </p>
        <p class="small text-muted mb-0">
            Full documentation lives in the <em>&ldquo;Dokumentation&rdquo;</em> tab above
            (auto-rendered from the plugin&rsquo;s <code>README.md</code>).
        </p>
    </div>
</div>
