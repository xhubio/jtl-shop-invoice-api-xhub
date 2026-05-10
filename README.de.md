# Invoice-api.xhub für JTL-Shop

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Kostenfreies, MIT-lizenziertes Plugin, das JTL-Shop-Bestellungen über
den Service [invoice-api.xhub.io](https://invoice-api.xhub.io) in
konforme E-Rechnungen umwandelt. Aktuell verfügbare Formate:
**PDF**, **XRechnung 3.0** (DE B2G/B2B, EN 16931 / Peppol BIS Billing 3.0)
und **ZUGFeRD 2.3/2.4** (Hybrid PDF/A-3 mit eingebettetem XML, DE/AT).

> 🇬🇧 English instructions in **`README.md`**.

## Funktionsweise

Sobald sich der Status einer Bestellung ändert, sendet das Plugin die
Bestelldaten an invoice-api.xhub.io und speichert die zurückgegebene
Rechnungsdatei unter `plugins/xhubio_invoice_api_xhub/files/<orderID>/`.
Trigger-Status (`off` / `on_pending` / `on_on_hold` / `on_processing` /
`on_completed`) und Zielformat sind pro Shop konfigurierbar. Rechnungen
können auch manuell für jede Bestellung im Settings-Tab des Plugins
erzeugt werden.

## Voraussetzungen

- JTL-Shop **5.2.0+** (wird vom Installer automatisch geprüft)
- PHP **8.1+**
- Ein invoice-api.xhub.io-Konto ([kostenfreie Registrierung unter console.invoice-api.xhub.io](https://console.invoice-api.xhub.io))

## Installation

1. Laden Sie `jtl-shop-invoice-api-xhub-1.0.0.zip` herunter
   (öffentliches Release in Vorbereitung — wenden Sie sich für einen
   frühen Zugang an support@invoice-api.xhub.io).
2. JTL-Shop-Admin → **Plugins → Plugin-Manager → Reiter "Verfügbar"**
   → **Plugin-ZIP hochladen** → ZIP auswählen.
3. Wechseln Sie zum Reiter **"Aktiviert"** → Plugin aktivieren.
4. **Plugins → Installierte Plugins → Invoice-api xhub for JTL-Shop**.
5. Reiter **"Configuration"** → ausfüllen:
   - **API-Schlüssel** (aus [console.invoice-api.xhub.io/api-keys](https://console.invoice-api.xhub.io/api-keys))
   - **Land** (Standard `DE`) und **Format** (`PDF` / `XRechnung` / `ZUGFeRD`)
   - **Trigger** (Standard: bei Statuswechsel auf "in Bearbeitung")
   - **Verkäufer-Block** — Firmenname, USt-IdNr., Anschrift, E-Mail, Telefon
   - **Bank** — IBAN + BIC (Pflicht für SEPA-Zahlungsanweisungen in XRechnung/ZUGFeRD)
   - **Länderspezifisch (DE)** — Default-Leitweg-ID für B2G-XRechnung (optional)
   - **Template** — UUID eines individuellen PDF-Templates aus
     [console.invoice-api.xhub.io/pdf/templates](https://console.invoice-api.xhub.io/pdf/templates) (optional)
6. **Speichern**.

## Erste Rechnung erzeugen

Nach der Installation:

1. **Plugins → Installierte Plugins → Invoice-api xhub for JTL-Shop**
   → Reiter **"Settings"** → Karte **"Generate invoice (manual trigger)"**.
2. Bestellung aus dem Dropdown auswählen → **"Generate now"** klicken.
3. Das Plugin sendet die Anfrage an invoice-api.xhub.io, speichert das
   Ergebnis lokal und zeigt eine grüne Bestätigungs-Card mit klickbarem
   Dateinamen an.
4. Die Tabelle **"Generated invoices"** im selben Reiter listet jede
   Rechnung mit Format, Dateigröße, Zeitstempel und Download-Link.

Für die **automatische** Erzeugung: Ändern Sie den Status einer
Bestellung in Ihrem JTL-Wawi-Workflow (oder direkt im JTL-Shop-Admin,
falls Ihre Konfiguration dies erlaubt). Der Listener des Plugins
reagiert auf Statuswechsel und erzeugt die Rechnung, sobald der neue
Status mit dem konfigurierten Trigger übereinstimmt.

## Eigene PDF-Templates — wenn das PDF trotzdem gleich aussieht

Tragen Sie die **Template**-UUID im Reiter "Configuration" ein. Das
Plugin sendet sie bei jeder Erzeugung an die API. Im Settings-Tab
sehen Sie pro erzeugter Rechnung welche Template-UUID tatsächlich
gesendet wurde (Spalte "Template" in der History-Tabelle) und den
Content-Hash der API-Antwort.

Ein eigenes Template erbt vom System-Default solange Sie Layout,
Logo und Farben unter
[console.invoice-api.xhub.io/pdf/templates](https://console.invoice-api.xhub.io/pdf/templates)
nicht angepasst haben. Sieht das erzeugte PDF immer wie der Standard
aus, kommt die UUID korrekt an der API an (sichtbar in der
History-Tabelle) — der Template-Inhalt selbst ist nur noch nicht
angepasst.

## Länderunterstützung (11 Länderprofile)

| Land | Code | XRechnung | ZUGFeRD | PDF |
|---|---|---|---|---|
| Deutschland | DE | ✅ | ✅ | ✅ |
| Österreich | AT | — | ✅ | ✅ |
| Frankreich | FR | — | — | ✅ |
| Italien | IT | — | — | ✅ |
| Spanien | ES | — | — | ✅ |
| Belgien | BE | — | — | ✅ |
| Niederlande | NL | — | — | ✅ |
| Bulgarien | BG | — | — | ✅ |
| Rumänien | RO | — | — | ✅ |
| Tschechien | CZ | — | — | ✅ |
| Ungarn | HU | — | — | ✅ |

API-Roadmap (ab Q3 2026): Factur-X (FR), FatturaPA (IT), Facturae (ES),
ebInterface (AT), UBL, ISDOC (CZ), NAV (HU). Die zusätzlichen Formate
erscheinen automatisch im Format-Dropdown des Plugins, sobald die
API sie unterstützt.

## Compliance

- **§14 UStG** — lückenlose, fortlaufende Rechnungsnummerierung bei
  Verwendung des `{seq:0000}`-Tokens. Der Zähler liegt in einer
  dedizierten DB-Tabelle und ist auch bei gleichzeitigen Erzeugungen
  race-condition-sicher.
- **EN 16931** + **XRechnung 3.0** + **Peppol BIS Billing 3.0** für
  deutsche B2G-XML-Rechnungen.
- **DSGVO** — alle Rechnungsdateien liegen auf Ihrem eigenen Server;
  extern wird ausschließlich der API-Aufruf an invoice-api.xhub.io
  zur Dokumenterzeugung selbst benötigt.
  `Bootstrap::uninstalled($deleteData=true)` entfernt sowohl die
  DB-Tabellen als auch alle erzeugten Dateien sauber.

## Speicherorte

- Rechnungsdateien: `plugins/xhubio_invoice_api_xhub/files/<orderID>/<filename>`
- Nummerierungs-Zähler: Tabelle `xplugin_xhubio_invoice_api_xhub_seq`
- Bestellungs-Metadaten (Dateiname, Format, Fehlerhistorie):
  Tabelle `xplugin_xhubio_invoice_api_xhub_meta`

Der Settings-Tab im Plugin-Admin zeigt den aufgelösten Speicherpfad,
die Gesamtanzahl Rechnungen und den Zeitpunkt der letzten Erzeugung.

## Support

- **API-Console:** [console.invoice-api.xhub.io](https://console.invoice-api.xhub.io)
- **E-Mail:** support@invoice-api.xhub.io
- **Plugin-Quellen / Issues:** öffentliches Release in Vorbereitung

## Lizenz

MIT — vollständiger Text im Reiter **"Lizenzvereinbarungen"**.
