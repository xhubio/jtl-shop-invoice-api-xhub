# Invoice-api.xhub for JTL-Shop

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Free, MIT-licensed plugin that turns JTL-Shop orders into compliant
e-invoices via the [invoice-api.xhub.io](https://invoice-api.xhub.io)
service. Live formats: **PDF**, **XRechnung 3.0** (German B2G/B2B,
EN 16931 / Peppol BIS Billing 3.0) and **ZUGFeRD 2.3/2.4** (hybrid
PDF/A-3 with embedded XML, Germany/Austria).

> 🇩🇪 Deutsche Anleitung im **`README.de.md`**.

## How it works

When an order's status changes the plugin sends the order to
invoice-api.xhub.io and stores the returned invoice file under
`plugins/xhubio_invoice_api_xhub/files/<orderID>/`. Trigger states
(`off` / `on_pending` / `on_on_hold` / `on_processing` / `on_completed`)
and target format are configurable per shop. Invoices can also be
generated manually for any order from the plugin's admin Settings tab.

## Requirements

- JTL-Shop **5.2.0+** (the installer auto-checks this against `info.xml`)
- PHP **8.1+**
- An invoice-api.xhub.io account ([sign up free at console.invoice-api.xhub.io](https://console.invoice-api.xhub.io))

## Install

1. Download `jtl-shop-invoice-api-xhub-1.0.0.zip` (public release pending —
   contact support.invoice-api@xhub.io for early access).
2. JTL-Shop admin → **Plugins → Plugin-Manager → "Verfügbar"** tab
   → **Plugin-ZIP hochladen** → upload the ZIP.
3. Switch to the **"Aktiviert"** tab → toggle the plugin on.
4. **Plugins → Installierte Plugins → Invoice-api xhub for JTL-Shop**.
5. Tab **"Configuration"** → fill in:
   - **API key** (from [console.invoice-api.xhub.io/api-keys](https://console.invoice-api.xhub.io/api-keys))
   - **Country** (default `DE`) and **Format** (`PDF` / `XRechnung` / `ZUGFeRD`)
   - **Trigger** (default: when order goes to "in Bearbeitung")
   - **Seller block** — your company name, VAT-ID, address, email, phone
   - **Bank** — IBAN + BIC (required for SEPA payment instructions in XRechnung/ZUGFeRD)
   - **Country-specific (DE)** — Default Leitweg-ID for B2G XRechnung (optional)
   - **Template** — UUID of a custom PDF template from
     [console.invoice-api.xhub.io/pdf/templates](https://console.invoice-api.xhub.io/pdf/templates) (optional)
6. **Save**.

## Generate your first invoice

After install:

1. **Plugins → Installierte Plugins → Invoice-api xhub for JTL-Shop**
   → tab **"Settings"** → **"Generate invoice (manual trigger)"** card.
2. Pick an order from the dropdown → click **"Generate now"**.
3. The plugin posts to invoice-api.xhub.io, stores the result on disk,
   and shows a green confirmation card with a clickable filename.
4. The **"Generated invoices"** history table on the same tab lists
   every invoice with format, byte size, timestamp and a download link.

For **automatic** generation: change an order's status in your
JTL-Wawi-driven workflow (or directly in the JTL-Shop admin if your
setup permits). The plugin's listener fires on order-status-change and
generates the invoice when the new status matches the configured trigger.

## Custom PDF templates — when the output looks the same

Configure the **Template** UUID in the Configuration tab. The plugin
sends it to the API on every generation. The Settings tab shows for
each generated invoice exactly which template UUID was sent (column
"Template" in the History table) and the API's content hash.

A custom template inherits the system default until you actually edit
its layout, logo and colors at
[console.invoice-api.xhub.io/pdf/templates](https://console.invoice-api.xhub.io/pdf/templates).
If your generated PDF still looks identical to the default, the UUID
is reaching the API correctly (visible in the History table) — the
template content itself has not yet been customised.

## Country support (11 country profiles)

| Country | Code | XRechnung | ZUGFeRD | PDF |
|---|---|---|---|---|
| Germany | DE | ✅ | ✅ | ✅ |
| Austria | AT | — | ✅ | ✅ |
| France | FR | — | — | ✅ |
| Italy | IT | — | — | ✅ |
| Spain | ES | — | — | ✅ |
| Belgium | BE | — | — | ✅ |
| Netherlands | NL | — | — | ✅ |
| Bulgaria | BG | — | — | ✅ |
| Romania | RO | — | — | ✅ |
| Czech Republic | CZ | — | — | ✅ |
| Hungary | HU | — | — | ✅ |

API roadmap (Q3 2026 onwards): Factur-X (FR), FatturaPA (IT),
Facturae (ES), ebInterface (AT), UBL, ISDOC (CZ), NAV (HU). The
plugin will surface these in the Format dropdown automatically as
soon as the API supports them.

## Compliance

- **§14 UStG** (Germany) — gap-free atomic invoice numbering when
  using the `{seq:0000}` token format. The counter lives in a
  dedicated DB table and is race-safe under concurrent generations.
- **EN 16931** + **XRechnung 3.0** + **Peppol BIS Billing 3.0** for
  German B2G XML invoices.
- **GDPR** — all invoice files live on your own server; nothing is
  stored externally except the API call to invoice-api.xhub.io for the
  document generation itself. `Bootstrap::uninstalled($deleteData=true)`
  cleanly removes both DB tables and all generated files.

## Where files and data go

- Generated invoices: `plugins/xhubio_invoice_api_xhub/files/<orderID>/<filename>`
- Sequence counter (for atomic numbering): `xplugin_xhubio_invoice_api_xhub_seq` table
- Per-order metadata (filename, format, error history): `xplugin_xhubio_invoice_api_xhub_meta` table

The Settings tab in the plugin admin shows the resolved storage path,
total invoice count, and the most-recent generation timestamp.

## Support

- **API console:** [console.invoice-api.xhub.io](https://console.invoice-api.xhub.io)
- **Email:** support.invoice-api@xhub.io
- **Plugin source / issues:** public release pending

## License

MIT — see the **"Lizenzvereinbarungen"** tab for the full text.
