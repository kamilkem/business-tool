# 0040. A purchase invoice keeps its items as printed, with quantities and unit prices as decimal strings

Status: Accepted

Date: 2026-11-25

## Context

ADR [0024](0024-expenses-independent-of-ksef.md) §1 gives a purchase invoice its totals and its VAT
lines only, and the FA(3) parser skipped the invoice's items (`Fa/FaWiersz`). The owner wants an
analysis of what the venue buys: products, categories and price history (spikes #232, #233), and the
expense details should show what was bought (#231). Both need every item of an imported invoice as
the seller printed it (#229).

An item's amounts come in two kinds. The net value, gross value and VAT of a row are rounded to the
currency's minor units, like the totals. Quantities and unit prices are not: FA(3) gives quantities
with up to 6 decimals (`TIlosci`) and unit prices with up to 8 (`TKwotowy2`), e.g. 0.7 l at
12.34567891 PLN. The discount (`P_10`) is also a `TKwotowy2`. ADR
[0010](0010-money-minor-units-and-utc-timestamps.md) stores money as integer minor units, which
cannot hold these values without rounding what the invoice says.

## Decision

1. **An item is a child of `PurchaseInvoice`** (`PurchaseInvoiceItem`, tenant-aware, in its own
   table, created only through the root, like `PurchaseInvoiceVatLine`). It is kept **as printed and
   never changed**, because it is what the source says, like the totals. Items are not checked
   against the totals or the VAT lines, nor against each other (positions may repeat; the table is
   keyed by the item's order in the document).
2. **An item holds**: its position (the printed row number; items are ordered by it), a required
   name, the seller's product code, the GTIN, the unit as printed, the quantity, the unit net and
   unit gross price, the discount, the net, gross and VAT values, the VAT rate, and whether it is a
   row "before the correction" of a correction invoice (which an analysis skips). Everything but the
   position, the name and that flag is optional, as invoices print them.
3. **Amounts rounded to minor units are integer minor units** (ADR 0010) of the invoice's currency,
   in `BIGINT` columns, negative ones included: the net, gross and VAT values.
4. **Quantities, unit prices and the discount are decimal strings**: `NUMERIC` columns (scale 6 for
   quantities, 8 for prices and the discount), strings in PHP and in the API with trailing zeros
   trimmed (`0.7`, `12.34567891`, `-2`), never floats, and not used for arithmetic in PHP. This is the
   one exception to "money in minor units" of ADR 0010: these values are what the invoice prints, not
   amounts the application adds up. Whatever computes with them later (unit prices per product, #233)
   does so in SQL `NUMERIC` or with an arbitrary-precision library, and states its rounding.
5. **Expenses stays country-neutral** (ADR 0024 §1): the item has no FA(3) names, and its VAT rate
   is a `VatRates` code of the organization's country, which the importer maps onto as for the VAT
   lines; a rate without a code (e.g. reverse charge) is null.
6. **A manual invoice has no items.** Entering items by hand is a later decision, if it is wanted;
   PATCH does not take items.
7. **`GET /expenses/{id}` returns the items**, ordered by position, `[]` when there are none. The
   list (`GET /expenses`) does not return them, so a month's list never loads every row.

## Alternatives considered

- **Quantities and prices in minor units, or scaled integers.** Minor units would round what the
  invoice prints (0.7 l, a unit price with 8 decimals). A fixed scale (e.g. ×10⁸ in a `BIGINT`) is
  exact but caps the integer part, makes every reader know the scale, and reads poorly in the API.
- **Floats.** Inexact by design; ADR 0010 rules them out.
- **The discount in minor units, as the other amounts.** FA(3) gives it with up to 8 decimals; a
  discount the currency cannot hold would have to be rounded or the invoice rejected.
- **Items read from the stored XML when shown, instead of stored.** No new table, but Expenses would
  parse FA(3), which ADR 0024 forbids, and the analysis could not query items.

## Consequences

- A new table `purchase_invoice_item`, deleted with its invoice. Invoices imported before this ADR
  have no items until a KSeF job re-reads them from the stored document (#230).
- The API has decimal strings next to integer minor units; the clients show them as text (or format
  them as decimals without converting to a float) and never add them up (#231).
- An item's position is not unique: the analysis must not use it as a key.
- ADR 0024 stays in force; this ADR adds to its §1.
