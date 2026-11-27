# 0048. A purchased item can be a bundle: a count of single packages next to the package size

Status: Accepted

Date: 2026-11-27

## Context

ADR [0041](0041-purchase-analysis-products-and-category-tree.md) §3 gives a purchased item one number for what one
unit of its quantity holds, the **package size** in the product's base unit. A product often arrives as a bundle: a
crate of 20 × 0.5 L bottles, a 6-pack, a carton of 24 cans. The owner has to enter such an item as one big size
("10 L"), so the number of single packages is lost. It cannot be told that "1 bundle = 20 bottles", compared with the
same product bought singly, or shown as "20 × 0.5 L" (#270).

The rules already read bundles from names, but only as totals (ADR [0042](0042-automatic-matching-of-purchased-items.md)
§5): `6x0,5L`, `24x330ml` are one size of 3 L and 7.92 L, and a seller's `kart.` or `CO` is a multiplier the owner's
confirmation teaches (ADR [0043](0043-units-of-unknown-count-and-the-sellers-multiplier.md)); the count is the first
thing thrown away. The aliases (ADR 0042 §3) remember the package size, so they would remember the total only.

Decided with the product owner (the issue): next to the package size, a **Bundle** option with a count of single
packages; the package size is then that of one single package; the same goods must give the same price whether
entered as a total or as a bundle.

ADR 0041 and 0042 are Accepted and are not edited beyond their Status lines (ADR 0000); this ADR amends 0041 §3 and §7
and 0042 §3, §5 and §6.

## Decision

1. **The bundle count lives on the item, and on the alias.** `purchased_item.bundle_count` is null (not a bundle) or a
   whole number from 2 to 9 999. The same product can be bought singly from one seller and in bundles from another, so
   it is not a property of the product. `product_alias.bundle_count` remembers it next to the alias's package size, for
   the alias kinds that remember a package size (ADR 0042 §3).
2. **The package size means one single package when the count is set.** An item's **size per unit of the quantity**
   (ADR 0041 §3) is `package size × bundle count`, or the package size when the count is null. 4 crates of 20 × 0.5 L
   have a package size of 0.5, a bundle count of 20 and a quantity of 4: 40 L. An item with a bundle count needs a
   package size (422 otherwise), and the size per unit must stay below 10¹² base units (422 otherwise). Existing items
   keep their total as the package size with no count; they are valid as they are and need no migration of data.
3. **Prices use the size per unit.** ADR 0041 §7 reads: price per reference quantity = amount ÷ (quantity × size per
   unit) × reference quantity, and the average Σ amount ÷ Σ (quantity × size per unit) × reference quantity. A bundle
   of 20 × 0.5 L and an item entered as 10 L give identical prices, averages, price changes, chart points and price
   sanity checks (ADR [0045](0045-matching-explains-itself-checks-prices-and-learns-from-history.md) §2). It is
   computed in SQL `NUMERIC` as before, and in PHP in the integer arithmetic of `PriceSanity`, from the already exact
   product of the two; never floats (ADR 0040 §4). Both the package size and the count come back in the API (a price
   point carries both), and the screens show `quantity × count × size`.
4. **A bundle read from a name fills the fields.** `ItemReader` reads `6x0,5L`, `0,5l x 6` and `24x330ml` as a count
   (6, 24) and a package size (0.5, 0.33), not as a total, and the seller's multiplier for a unit of unknown count
   (ADR 0043 §3) is a count too: a size of 1 L on a unit the seller prints for 6 is a package size of 1 and a count
   of 6, and a multipack on such a unit has the two counts multiplied (a count that would exceed 9 999 is dropped, and
   the total kept as the size). The reading's total, which is what every comparison and every confidence rule of ADR
   0042 §6 and 0043 §2 uses, is the same number as before, so what is `assigned` and what is `suggested` does not
   change: only what the owner then sees and confirms, "6 × 0.5 L" and not "3 L". A person's confirmation teaches
   the multiplier from the item's size per unit, as before.
5. **The aliases and the confirmations carry it.** A person's confirmation writes the item's package size and its
   count into the aliases it teaches (`LearnProductAliasesCommand`, `app:purchasing:backfill-aliases`), the last
   confirmation winning. Matching with an alias gives the item the alias's package size and count; when an alias's
   size per unit equals the reading's, the reading's breakdown is the one stored (it is what the name says). The
   decision's package is a value object, `PackageSize` (the single size and the count; Domain), so that no code
   multiplies by hand.
6. **The model is not asked for a count.** Its answer stays the size per unit of the quantity, a total, as in ADR 0042
   §8: it would only be a second source of the same guess. A decision from the model has no count; the owner enters
   it.
7. **The API.** `PurchasedItem` (resource and view), a price point, `PATCH /purchased-items/{id}` and `POST
   /purchased-items/assign` carry `bundleCount` (integer or null). In the merge-patch, `packageSize: null` with a count
   is a 422. In assign, a count goes with a `packageSize` and applies to every item that gets it (a count without a
   package size is 422); with no `packageSize`, every item keeps its own size and count. New error code:
   `purchasing.invalid_bundle_count` with the variants `out_of_range`, `without_package_size` and `too_large`.
8. **The admin** shows a **Bundle** checkbox and the count under the package size in the item dialog and the assign
   dialog; the product's purchases and the review queue show `count × size`. The proposal accept form has no package size
   field (ADR [0044](0044-the-model-proposes-products-the-owner-creates-them.md)): its items keep their package or
   start from what the text strongly states, which has no count; the owner sets one in the item dialog.

## Alternatives considered

- **On the product.** One bundle size per product contradicts the case the issue names: a seller who sells it singly
  and another who sells the crate. The size belongs to the purchase, as ADR 0041 §3 decided for the package size.
- **On the alias only.** The alias is only what the owner confirmed for a key; the item is what is bought and priced.
- **Keeping the package size as the total and the count as a display hint.** One number, nothing to multiply in SQL,
  but then "bundle of 20" could silently disagree with the size (20 × 0.5 L with a total of 12 L), and the count would
  not be the fact the owner enters. The issue asks for the count and the single size as the data.
- **Variants of a product (a "crate" and a "bottle" of one beer).** Rejected in ADR 0041 for sizes; the owner wants one
  price across them.
- **A decimal count.** A bundle holds whole packages; a count of 1 is no bundle, so the range is 2 and up.
- **Asking the model for the count.** The count is the seller's, and the model only guesses it (ADR 0043 §Alternatives).

## Consequences

- A migration adds two nullable integer columns, `purchased_item.bundle_count` and `product_alias.bundle_count`, with a
  check that the count is between 2 and 9 999. No data changes; items entered as totals stay as they are, and a
  person who edits one can split it into a bundle.
- Every SQL that multiplied `quantity × package_size` multiplies by `COALESCE(bundle_count, 1)` as well; a regression
  test shows a bundle and the same goods entered as a total give the same prices.
- `ItemReading`, `MatchDecision` and the aliases gain the count; the confidence rules and all totals are unchanged.
  Existing aliases have no count and give a total, which keeps working.
- A seller's `kart.` of 24 that the owner confirmed as one count is shown as "24 × 0.5 L" without further input.
- The review queue's "suggested" items show the breakdown, so a wrong multipack reading (a seller who counts the
  bottles, not the packs) is visible at a glance.
