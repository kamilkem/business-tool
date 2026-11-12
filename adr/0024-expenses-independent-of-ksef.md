# 0024. Expenses is independent of KSeF: a country-neutral purchase invoice with its original document, and KSeF in its own module that feeds it

Status: Accepted

Date: 2026-11-12

## Context

ADR [0023](0023-ksef-purchase-invoice-import.md) decided how purchase invoices are imported from
KSeF. It also placed the integration inside the Expenses module: the client port as
`App\Expenses\Domain\Ksef\KsefClientInterface`, the adapter and the FA(3) parser in
`App\Expenses\Infrastructure\Ksef`, the `KsefConnection` aggregate in `App\Expenses` (§1, §3), and the
raw XML in an Expenses table described in KSeF terms (form code, download time; §6). It already asked
for everything else in Expenses to be country-neutral.

While scaffolding the module (#47) the owner decided that this is not enough: Expenses is what the
venue bought, whichever way the invoice arrived (KSeF, typed in from a receipt, a foreign invoice,
another country's e-invoice system later). It should not know KSeF or FA(3) at all; KSeF is one
integration that uses it. ADR [0002](0002-locale-specifics-behind-organization-settings.md) §3 asks
the same of country-specific code, and ADR [0004](0004-layered-modules-and-cqrs-buses.md) already
gives modules a way to use each other without coupling: the buses.

## Decision

1. **`App\Expenses` is country-neutral and knows no KSeF or FA(3) code.** Its `PurchaseInvoice`
   aggregate holds:
   - a `source` (enum `manual` | `ksef`; naming the source is the only mention of KSeF in Expenses)
     and, for an imported invoice, an opaque `externalId` (for KSeF, the KSeF number), unique per
     organization and source, which makes an import idempotent; for a correction, the corrected
     invoice's `correctedExternalId` in the same source, when given;
   - the invoice number, the seller's tax ID and name, the issue date (`BusinessDate`, ADR
     [0017](0017-business-dates-and-periods-as-value-objects.md)), the kind (`regular`, `correction`,
     `advance`, `settlement`, `simplified`, `advance_correction`, `settlement_correction`), onto which
     an importer maps its own codes;
   - the invoice's currency and the tax currency (the organization's currency when the invoice is
     stored); net, VAT and gross totals; VAT lines per rate with net and VAT and, for an invoice in
     another currency than the tax currency, the VAT also in the tax currency (required then, absent
     otherwise). Amounts are integer minor units (ADR [0010](0010-money-minor-units-and-utc-timestamps.md))
     in `BIGINT` columns and may be negative. Totals are stored as given and not checked against
     each other or the lines: advances and corrections need not add up. Rate codes are those of the
     organization's country, as income uses them (`VatRates`, ADR
     [0022](0022-income-daily-report-per-register.md) §6), each at most once per invoice.
2. **The original document is a neutral `PurchaseInvoiceDocument` in Expenses**, replacing the KSeF
   XML table of ADR 0023 §6: the content unchanged, a `format` string named by whoever stored it
   (for KSeF, the form code), its SHA-256 computed by Expenses, and when it was received. One per
   invoice at most, keyed by the invoice's id, in its own table that the invoice and its lists never
   load, never edited. It is stored with its invoice in one flush
   (`PurchaseInvoiceRepositoryInterface::add($invoice, $document)`), so an invoice is never stored
   without the document it came with.
3. **KSeF is its own module, `App\Ksef`** (Domain / Application / Infrastructure): the
   `KsefConnection` aggregate, the KSeF client port and its adapter (#50), the FA(3) parser (#51) and
   the sync (#52). The namespaces of ADR 0023 §1 and §3 move there unchanged in substance (e.g.
   `App\Ksef\Domain\KsefClientInterface`, `App\Ksef\Infrastructure\...`). The module maps FA(3) onto
   Expenses' neutral data and creates purchase invoices only through the command bus, with a neutral
   Expenses command added in #52; it reads Expenses through the query bus. Dependencies go one way,
   Ksef to Expenses, under the existing Deptrac rules (another module's Application only through the
   buses).
4. Everything else in ADR 0023 stays: KSeF API 2.0 through a thin client, KSeF-token authentication,
   Poland only, incremental sync by permanent-storage date, the schedule and limits, FA(3) only with
   amounts from the XML, and no KSeF in CI.

## Alternatives considered

- **KSeF inside Expenses, as ADR 0023 §1 and §3 placed it.** Less wiring, but a country-neutral
  module would carry one country's integration, and the next source (another country's e-invoices,
  an e-mail inbox) would land there too.
- **The raw XML in the Ksef module.** Expenses would be free of even an opaque document, but showing
  or downloading an invoice's original (#53) would cross modules for every expense, and a scan
  attached to a manual expense later would need a second document store. The neutral document serves
  both.
- **An origin of only `imported` | `manual`, without naming the source.** Two importers could then
  produce the same external id. An explicit source keeps ids apart per source and shows where an
  invoice came from.

## Consequences

- ADR 0023 stays in force except for where the code lives (§1, §3) and the shape of the XML table
  (§6); its status notes that.
- Issues #50, #51 and #52 name `App\Expenses\...\Ksef` namespaces and need their text updated.
  `App\Ksef` is registered (services, Doctrine mapping, exception mapping) with its first code, in #50.
- The KSeF import needs an Expenses command and the organization's country and currency to store an
  invoice; Expenses checks the data whoever sends it, so a parser bug surfaces as a rejected invoice
  instead of a wrong one.
- The `ksef` case of the source enum is the one place Expenses names KSeF; a new source adds a case.
- `CLAUDE.md`'s pattern for calling an external API cites this ADR: a country-specific integration is
  its own module that uses the business module through the buses.
