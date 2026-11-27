# 0041. The purchase analysis is a Purchasing module: invoice items grouped into products, a category tree, and prices per a reference quantity the owner picks

Status: Accepted (points 3 and 7 amended for bundles by [0048](0048-bundles-next-to-the-package-size.md); point 6, the gross of an item that prints only its net, by [0051](0051-gross-of-net-priced-items-is-derived-from-the-vat-rate.md))

Date: 2026-11-25

## Context

The owner wants an **Analiza** tab under Expenses (spike #232): what the venue buys, at what price,
and how prices change. Since ADR [0040](0040-purchase-invoice-items-as-printed.md) every imported
invoice keeps its items as printed, and #230 reads them for invoices imported earlier. A category
per invoice said little (one wholesaler invoice holds whisky, juice and napkins), so #228 removed it.

The same thing is printed differently by each seller and over time ("JACK DANIELS 0,7L 40%",
"Whiskey Jack Daniel's Old No.7 700ml", "J.DANIELS 1L"), and in different package sizes, which the
owner wants compared on one quantity (0.7 L and 1 L both per 1 L). Invoices also carry items that
are not goods: rent, electricity, delivery fees, deposits for returnable packaging (*kaucja*).

Expenses stores what the invoice says, immutable and country-neutral (ADR
[0024](0024-expenses-independent-of-ksef.md), 0040). Which product an item is, its package size and
its category are interpretations that the owner corrects over time. How items are matched to
products automatically is spike #233; this ADR fixes the model it fills in.

Decided with the product owner:

- Categories are a new model for what was bought, with a parent of the same kind (n:1) and **any
  depth**. The old expense category list goes.
- The category sits **on the product**; every item matched to it follows.
- Prices are compared **net by default, or gross**, with a toggle on the screen.
- Items that are not goods are **products without a unit** (counted in spend, no price per unit),
  and the owner can also **exclude** what should not count at all.
- **Price change** is measured against the previous purchase of the product, from any seller.
- The **reference quantity is picked per product** by the owner (beer per 0.5 L, coffee per 1 kg),
  1 L, 1 kg or 1 piece by default.

## Decision

1. **A new module `App\Purchasing`** (Ui `Ui\Http\Purchasing`) owns products, product categories,
   its own record of each purchased item and the analysis queries. It uses Expenses only through
   the buses (ADR [0004](0004-layered-modules-and-cqrs-buses.md)). It is country-neutral (ADR
   [0002](0002-locale-specifics-behind-organization-settings.md)) apart from default category names
   by country. Its screens are for owners and managers, under the `expenses` capability (ADR
   [0021](0021-admin-for-owners-and-managers.md)).
2. **Expenses publishes `PurchaseInvoiceItemsRecorded`** (its first domain event) whenever an
   invoice gets its items: on import and through `RecordPurchaseInvoiceItemsCommand` (#230). The
   event carries primitives: the invoice's id, issue date, kind, currency and seller (tax ID, name),
   and each item's index in the document and its printed fields. Purchasing's event handler sends
   `RecordPurchasedItemsCommand`, an upsert keyed by (invoice id, item index), as in the "one
   module's record books another module's aggregate" pattern of ADR
   [0033](0033-cash-flow-storages-and-day-close.md) §9. Items stored before this ADR are copied once
   by a Purchasing job that pages through an Expenses query, queued by the console command
   `app:purchasing:record-existing-items` (ADR [0025](0025-background-jobs-scheduler-and-job-bus.md)).
   Imported invoices are never deleted and manual ones have no items, so there is no deletion event
   yet; manual items, if they come, add one.
3. **`PurchasedItem`** (tenant-aware) is Purchasing's record of one invoice item. The copied fields
   never change. Purchasing's own fields are:
   - the **product** (nullable);
   - the **link status**: `unassigned`, `suggested` (by matching, to confirm), `assigned` (by
     matching, confident) or `confirmed` (by a person). A re-run of matching (#233) never changes a
     `confirmed` link;
   - the **package size**: a decimal string in the product's base unit (L, kg, pc) per one unit of
     the item's quantity. 6 pieces of a 0.7 L bottle have a package size of 0.7, so 4.2 L were
     bought; 30 `l` of draught beer has 1. It is null until known, and then the item counts in spend
     but not in quantities or prices.
4. **`Product`** (tenant-aware) has:
   - a name, unique in the organization;
   - a category (nullable);
   - a **measure**: `volume` (base unit L), `mass` (kg), `count` (pc) or `none`;
   - a **reference quantity**: a decimal string in the base unit, 1 by default, that the owner
     changes (0.5 for beer per 0.5 L). It is null for `none`;
   - an **excluded** flag.

   A product with measure `none` (rent, electricity, *kaucja*) counts in spend and has no quantity,
   price or price history. An excluded product (and every item linked to it) is left out of every
   total. Excluding a thing for good means linking its items to such a product, so matching learns
   it like any other product. Products are created, renamed, categorized, changed and **merged**:
   the merged product's items and the aliases #233 keeps move to the remaining one, and the merged
   product is deleted.
5. **`ProductCategory`** (tenant-aware) has a name and an optional parent `ProductCategory` (n:1,
   **any depth**). Names are unique among siblings, compared case-insensitively. Moving a category
   under itself or one of its descendants is refused. A category is deleted only while it has no
   subcategory and no product (409 otherwise); there is no archive. A new organization gets default
   categories by country (PL: Alkohol › Wódka, Whisky, Rum, Gin, Likiery, Wino, Piwo; Napoje
   bezalkoholowe › Soki, Napoje gazowane, Woda, Syropy; Kawa i herbata; Żywność; Chemia i środki
   czystości; Opakowania i artykuły jednorazowe; Wyposażenie; Media › Prąd, Gaz, Woda, Internet i
   telefon; Czynsz; Usługi › Transport, Serwis, Księgowość; Kaucje; Inne; English names elsewhere).
   Existing organizations get them from the migration, as in ADR
   [0030](0030-payment-methods-per-organization.md). **The old `ExpenseCategory` list goes**: its
   table, endpoints, default categories and admin page. Nothing refers to it since #228. The
   Categories tab under Expenses manages the new tree.
6. **The amounts.** An item's **net** is its printed net value, or its gross minus its VAT; its
   **gross** is its printed gross value, or its net plus its VAT. When neither can be derived, the
   item is left out of that basis and the screen says how many were. The API returns both bases and
   the admin shows net unless the viewer toggles to gross; the toggle is remembered per viewer in
   the browser.
   - **Spend** in a period sums the items of invoices whose issue date is in the period (a
     `BusinessPeriod`, ADR [0017](0017-business-dates-and-periods-as-value-objects.md)). A
     correction's rows "before correction" count negative, so a correction adds its difference.
   - Only items in the **organization's currency** count. Items of invoices in another currency are
     left out, and the screen says how many.
7. **Prices.** A **price point** is one purchased item:
   - of a product with a measure other than `none`, not excluded;
   - with a known package size and a positive quantity;
   - on an invoice that is not a correction;
   - in the organization's currency.

   Its price per reference quantity is amount ÷ (quantity × package size) × reference quantity. The
   product's **average price** in a period is Σ amount ÷ Σ (quantity × package size) × reference
   quantity. Both are computed in SQL `NUMERIC` (ADR 0040 §4) and rounded half-up to minor units only
   in the response. The **price change** of a price point is its difference and percentage against
   the product's previous price point, ordered by issue date and then by when the invoice was
   stored, from any seller.
8. **The analysis is aggregated in SQL on request**, on Purchasing's tables only (recursive CTEs
   for the category tree). The first bar buys on the order of a thousand items a month, which needs
   no read model. There are two queries:
   - the **overview** for a period: the category tree with spend per category (its subcategories
     included), and per product the spend, quantity in reference quantities, average and last
     price, and last change. Products without a category are under "Uncategorized", and items
     without a product under "Not assigned", which links to the review queue;
   - the **price history** of a product: every price point with its seller and invoice.
9. **Screens** (admin, Expenses nav: Invoices · Analiza · Categories):
   - the Analiza overview (`PeriodPicker`, the net/gross toggle, the tree drilling down to
     products);
   - the product detail (price history chart with points coloured by seller, the purchases with
     links to their invoices, the product's settings);
   - the review queue of `unassigned` and `suggested` items, to assign to a product or to a new one
     with its package size, to confirm, or to exclude;
   - product management (rename, merge, move items).
10. **Charts use shadcn/ui's chart component on Recharts**, added to `packages/ui` with the
    component's theme tokens, so charts follow the light and dark theme like the rest of the UI.

## Alternatives considered

- **Products and links inside `App\Expenses`.** No copy of the items, but Expenses would mix what
  the invoice says (immutable, country-neutral) with the owner's interpretation, which changes all
  the time, and grow a second aggregate family with its own screens.
- **Reading items from Expenses through the query bus on each request, instead of a copy.** The
  aggregations need SQL joins of items, links, products and the category tree. Through the bus,
  every item of a period would be loaded into PHP and summed there.
- **The category on each item.** More clicks, and one product could end up in two categories. The
  owner chose the product.
- **Two fixed levels of categories.** Simpler totals and UI, but the owner wants any depth
  (Alkohol › Whisky › Bourbon).
- **Reusing the `ExpenseCategory` list.** It was the invoice's category, dropped in #228, and it is
  flat.
- **Net or gross as an organization setting.** The owner wants both at hand, with a toggle.
- **A fixed reference quantity (1 L, 1 kg, 1 pc).** Simpler, but a beer is compared per 0.5 L at the
  bar. The owner picks it per product, and the default stays 1.
- **Package sizes as product variants** (Jack Daniel's 0.7 L and 1 L as two products under one).
  The owner wants one price across sizes. The size belongs to the purchase, so it sits on the item.
- **A read model or materialized view for the analysis.** Not needed at this volume. Adding one
  later changes no API.
- **Chart.js or ECharts.** Both are fine, but neither follows the shadcn/ui components and theme
  tokens the admin already uses.

## Consequences

- A new module, registered in `services.yaml`, `doctrine.yaml` and Deptrac, with tables for
  products, product categories and purchased items. Each tenant-aware endpoint gets its isolation
  test.
- Expenses publishes its first domain event. The copy in Purchasing duplicates item fields: the
  price of keeping the modules apart.
- `app:purchasing:record-existing-items` runs once per environment after deploy, after
  `app:ksef:read-invoice-items` (#230).
- The `ExpenseCategory` list and its data are dropped. Its error codes leave `error-codes.txt`.
- Matching (#233) only fills `product`, `status` and `package size` on purchased items and keeps
  its own aliases. Until it lands, every item starts `unassigned` and the owner assigns from the
  review queue.
- Recharts becomes a dependency of `packages/ui` and of the admin bundle.
- Prices and quantities are computed in SQL `NUMERIC` and rounded only in the response, so totals
  never drift through floats (ADR 0010, 0040).
