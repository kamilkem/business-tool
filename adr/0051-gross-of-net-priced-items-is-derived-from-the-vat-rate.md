# 0051. The gross of an item that prints only its net is derived from its VAT rate

Status: Accepted

Date: 2026-11-27

## Context

ADR [0041](0041-purchase-analysis-products-and-category-tree.md) §6 defines an item's **gross** as its
printed gross value, or its net plus its printed VAT, and leaves the item out of the gross basis when
neither exists. The screen counts those items ("n items without a gross amount").

On staging, 453 of 591 purchased items (77%) are left out of the gross basis. Most supplier invoices
are priced net: an FA(3) row then prints its net value (`P_11`) and its VAT rate (`P_12`), but no gross
value (`P_11A`) and no line VAT (`P_11Vat`, printed on only 27 of the 591 items). So with gross
selected, most products show no gross price, no gross average and no gross spend that covers what was
bought. Every one of the 453 has a net amount and a rate code (23 → 268, 5 → 141, 8 → 43, `zw` → 1),
so the information to derive the gross is already stored (ADR
[0040](0040-purchase-invoice-items-as-printed.md)).

## Decision

1. **The gross of an item is, in this order:** its printed gross value; its net plus its printed VAT;
   its net plus the VAT at its rate, `net × (100 + rate) / 100`, rounded to minor units (PostgreSQL's
   `ROUND` on `NUMERIC`: half away from zero), per item. The rate is the item's rate code: a number is
   the percentage, `zw` (exempt) is 0. A code with no percentage (an item with no code, or a rate that
   has none) gives no derived gross, and the item stays out of the gross basis as before.
2. **It is derived when the analysis is read**, in the `item` CTE of `PurchaseAnalysisRepository`, where
   the net and the gross already are. Nothing is stored and nothing is migrated: the stored item stays
   as printed (ADR 0040), and every existing item is covered as soon as this is deployed. A correction's
   rows are derived the same way, so the "before" rows still count negative.
3. **Everything gross in the analysis follows**: spend per product and category, the average, last and
   previous gross price and the change, and the count of items without a gross amount. The item screens
   keep showing the printed values only.
4. **A derived gross is an estimate.** It is the line's net with the VAT of its rate; a supplier that
   rounds VAT per invoice, not per line, can print a gross that differs by a grosz or so. The analysis
   does not mark which items are derived.

## Alternatives considered

- **Leave it as it is.** The gross toggle is then of use only for the minority of invoices that print
  a gross, which is the problem.
- **Derive and store the gross when the items are recorded.** It would put an estimate in a column that
  holds what the invoice says (ADR 0040), need a backfill for items already recorded, and need another
  one whenever the rule changes. Deriving on read costs one expression in a query that already computes
  the net and gross per item.
- **Take the VAT from the invoice's totals per rate (`P_13_x`, `P_14_x`) and share it over the lines.**
  It would match the printed totals to the grosz, but it needs the invoice's totals in Purchasing's
  tables and a rule for sharing the rounding, to correct differences of a grosz on a price per litre.
- **Sum the net per rate and apply the rate once per group.** Price points are single items, so each
  needs its own gross.

## Consequences

- With gross selected, the analysis covers the items of net-priced invoices too (on staging from 23%
  to every item that has a net and a rate of a number or `zw`). The count of items without a gross
  amount falls to the items with no usable rate.
- The gross of such an item can differ by a grosz or so from a printed one, and from a gross summed
  from the invoice's totals. Net remains the default basis (ADR 0041 §6), and it is exact.
- The rate codes are the ones the importer maps from the invoice (ADR
  [0040](0040-purchase-invoice-items-as-printed.md), `Fa3InvoiceParser`): the numbers are percentages
  and `zw` is exempt. A new integration whose codes mean something else has to map them to these or
  extend the expression.
- This ADR amends ADR 0041 §6 (the definition of the gross); the rest of ADR 0041 stands.
