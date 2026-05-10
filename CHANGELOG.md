# Changelog

## 1.0.0 — initial release

* **3 invoice formats live:** PDF (all 11 country profiles), XRechnung 3.0 (Germany B2G/B2B, EN 16931 / Peppol BIS Billing 3.0), ZUGFeRD 2.3/2.4 (Germany/Austria, hybrid PDF/A-3 with embedded XML).
* **Auto-generation on order status change** — Hook listener fires when a configured trigger state (`off`, `on_pending`, `on_on_hold`, `on_processing` (default), `on_completed`) is reached.
* **Manual "Generate now" button** in the plugin admin Settings tab — pick any order, regenerate fresh on demand. History table tracks every artefact with format, byte size and timestamp.
* **§14 UStG-compliant invoice numbering** via dedicated DB sequence table — gap-free under concurrent generations when using the `{seq:0000}` token format.
* **Custom invoice templates** — create in console.invoice-api.xhub.io/pdf/templates, paste UUID into plugin config (global default or per-order override via meta table).
* **Local file storage** — invoices stored under `plugins/xhubio_invoice_api_xhub/files/<orderID>/`. No third-party storage; nothing leaves your server except the API call to invoice-api.xhub.io itself.
* **GDPR-friendly uninstall** — both `xplugin_xhubio_invoice_api_xhub_seq` + `xplugin_xhubio_invoice_api_xhub_meta` tables and all generated files are removed when the plugin is uninstalled with "delete data".
* **JTL-Shop 5.2.0+** compatible · **PHP 8.1+** required.
